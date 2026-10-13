<?php
namespace Riskified\AdaptiveCheckout\Model\Builder;

use Adyen\Client as AdyenClient;
use Adyen\Model\BinLookup\Amount as BinLookupAmount;
use Adyen\Model\BinLookup\CostEstimateRequest;
use Adyen\Model\Checkout\CardDetailsRequest;
use Adyen\Payment\Helper\Config as AdyenConfig;
use Adyen\Payment\Helper\Data as AdyenDataHelper;
use Adyen\Service\BinLookupApi;
use Adyen\Service\Checkout\PaymentsApi;
use Magento\Checkout\Model\Session;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Riskified\AdaptiveCheckout\Model\Request\Advice as AdviceRequest;
use Riskified\AdaptiveCheckout\Model\Security\CartStateHasher;
use Riskified\Decider\Model\Api\Log;
use Riskified\Decider\Model\Api\Order\Helper;
use Riskified\OrderWebhook\Model\Checkout;
use Riskified\OrderWebhook\Model\PaymentDetails;

class Advice
{
    private $adviceRequestModel;
    private $checkoutSession;
    private $json;
    private $quoteIdMaskFactory;
    protected $cartRepository;
    protected $logger;
    protected $helper;
    protected $remoteAddress;
    protected $hasher;
    protected $paymentTokenManagement;
    protected $adyenDataHelper;
    protected $adyenConfig;

    public function __construct(
        QuoteIdMaskFactory $quoteIdMaskFactory,
        CartRepositoryInterface $cartRepository,
        Helper $helper,
        Log $logger,
        AdviceRequest $requestAdvice,
        Session $checkoutSession,
        RemoteAddress $remoteAddress,
        CartStateHasher $hasher,
        PaymentTokenManagementInterface $paymentTokenManagement,
        AdyenDataHelper $adyenDataHelper,
        AdyenConfig $adyenConfig
    ) {
        $this->quoteIdMaskFactory     = $quoteIdMaskFactory;
        $this->adviceRequestModel     = $requestAdvice;
        $this->checkoutSession        = $checkoutSession;
        $this->cartRepository         = $cartRepository;
        $this->logger                 = $logger;
        $this->helper                 = $helper;
        $this->remoteAddress          = $remoteAddress;
        $this->hasher                 = $hasher;
        $this->paymentTokenManagement = $paymentTokenManagement;
        $this->adyenDataHelper        = $adyenDataHelper;
        $this->adyenConfig            = $adyenConfig;
    }

    /**
     * @throws \Exception
     */
    public function build($params)
    {
        $quoteId = $params['quote_id'];

        // Only masked IDs are accepted. The numeric-id branch used to resolve
        // raw primary keys through cartRepository->get($int) with no ownership
        // check, mirroring the same IDOR the controller had.
        if (is_numeric($quoteId)) {
            $this->logger->log('Advice builder: rejected numeric quote id ' . $quoteId);
            return $this->notCapturedResult();
        }

        try {
            $quoteIdMask = $this->quoteIdMaskFactory->create()->load($quoteId, 'masked_id');
            if (!$quoteIdMask->getQuoteId()) {
                throw new \Exception('Invalid masked quote ID: ' . $quoteId);
            }
            $cart = $this->cartRepository->get($quoteIdMask->getQuoteId());
        } catch (\Exception $e) {
            $this->logger->log('Failed to load cart for advice builder. Quote ID: ' . $quoteId . '. Error: ' . $e->getMessage());
            return $this->notCapturedResult();
        }

        return $this->buildForQuote($cart, $params);
    }

    /**
     * Same builder logic as build() but skips masked-id resolution — used by
     * backend flows (place-order plugins) that already hold the quote and don't
     * need session/masked-id validation.
     */
    public function buildForQuote($cart, array $params): self
    {
        $currencyObject = $cart->getCurrency()->getQuoteCurrencyCode();
        $gateway = $params['gateway'] ? $params['gateway'] : 'unavailable';

        // Use email from quote (server-side) to prevent tampering
        // Frontend email parameter was already validated and stored in quote
        $emailToUse = $cart->getCustomerEmail();

        $order_array = [
            'id' => $cart->getId(),
            'email' => $emailToUse,
            'created_at' => $this->helper->formatDateAsIso8601($cart->getCreatedAt()),
            'currency' => $currencyObject,
            'updated_at' => $this->helper->formatDateAsIso8601($cart->getUpdatedAt()),
            'gateway' => $gateway,
            'source' => 'desktop_web',
            'browser_ip' => $this->remoteAddress->getRemoteAddress(),
            'note' => $cart->getCustomerNote(),
            'total_price' => $cart->getGrandTotal(),
            'subtotal_price' => $cart->getSubtotal(),
            'discount_codes' => null,
            'taxes_included' => true,
            // HMAC of the session id, not the raw session id. The raw value is
            // a bearer credential — leaking it (in outbound payloads or logs)
            // allowed full session takeover.
            'cart_token' => $this->hasher->cartToken((string) $this->checkoutSession->getSessionId()),
        ];

        $this->helper->setOrder($cart);

        $payload = array_filter($order_array, fn ($val) => $val !== null && $val !== false);
        $checkoutData = new Checkout($payload);

        $checkoutData->customer = $this->helper->getCustomer();
        $checkoutData->shipping_address = $this->helper->getShippingAddress();
        $checkoutData->billing_address = $this->helper->getBillingAddress();
        $checkoutData->payment_details = $this->helper->getPaymentDetails();
        $checkoutData->line_items = $this->helper->getLineItems();
        $checkoutData->client_details = $this->helper->getClientDetails();

        // Adyen paths are backend-verified only — vault lookup or Adyen
        // binLookup. Frontend-supplied bin/type are ignored for adyen_cc
        // because Adyen's iframes prevent the client from ever holding
        // authoritative card data.
        $cardDetails = $this->resolveCardDetailsFromVault($params, $cart)
            ?? $this->resolveCardDetailsFromAdyen($params, $cart)
            ?? [
                'type'  => $this->isAdyenGateway($gateway) ? null : ($params['type'] ?? null),
                'last4' => $this->isAdyenGateway($gateway) ? null : ($params['last4'] ?? null),
                'bin'   => $this->isAdyenGateway($gateway) ? null : ($params['bin'] ?? null),
            ];

        $checkoutData->payment_details = new PaymentDetails(array_filter([
            "credit_card_company" => $cardDetails['type'],
            "credit_card_number"  => $cardDetails['last4'] ? "XXXX-XXXX-XXXX-" . $cardDetails['last4'] : null,
            "credit_card_bin"     => $cardDetails['bin'],
        ], fn ($val) => $val !== null && $val !== false));

        $this->json = $checkoutData;

        return $this;
    }

    /**
     * @return mixed
     * @throws \Riskified\OrderWebhook\Exception\CurlException
     * @throws \Riskified\OrderWebhook\Exception\UnsuccessfulActionException
     */
    public function request(): mixed
    {
        // Log a non-PII summary, not the full payload. The full Checkout JSON
        // contains email, addresses, IP, customer name, and line items —
        // logging it sent that into rotating module logs and any downstream
        // log shipping. Operational visibility only needs quote id, gateway,
        // currency, and totals to correlate a call with a problem.
        $this->logger->log(sprintf(
            'Advice call: quote_id=%s gateway=%s currency=%s subtotal=%s total=%s',
            $this->json->id ?? 'n/a',
            $this->json->gateway ?? 'n/a',
            $this->json->currency ?? 'n/a',
            $this->json->subtotal_price ?? 'n/a',
            $this->json->total_price ?? 'n/a'
        ));

        return $this->adviceRequestModel->advise($this->json);
    }

    private function notCapturedResult(): \stdClass
    {
        $stdClass = new \stdClass();
        $checkout = new \stdClass();
        $checkout->status = 'notcaptured';
        $stdClass->checkout = $checkout;

        return $stdClass;
    }

    /**
     * Resolve card details from a Magento vault token instead of trusting the
     * frontend-supplied fields. Returns null if no public_hash was provided or
     * the token can't be resolved to the current customer — caller falls back
     * to the raw params. Ownership is enforced via customer_id lookup; a token
     * cannot be exercised across customers even if the hash leaks.
     */
    private function resolveCardDetailsFromVault(array $params, $cart): ?array
    {
        $publicHash = $params['public_hash'] ?? null;
        if (!$publicHash) {
            return null;
        }

        $customerId = (int) $cart->getCustomerId();
        if ($customerId <= 0) {
            $this->logger->log('Advice builder: public_hash supplied for guest cart, ignoring');
            return null;
        }

        try {
            $token = $this->paymentTokenManagement->getByPublicHash($publicHash, $customerId);
        } catch (\Exception $e) {
            $this->logger->log('Advice builder: vault lookup failed. Error: ' . $e->getMessage());
            return null;
        }

        if (!$token || !$token->getIsActive()) {
            $this->logger->log('Advice builder: no active vault token for hash');
            return null;
        }

        $details = json_decode((string) $token->getTokenDetails(), true) ?: [];

        return [
            'type'  => $details['type'] ?? null,
            'last4' => $details['maskedCC'] ?? null,
            'bin'   => $details['bin'] ?? null,
        ];
    }

    /**
     * Resolve card details from Adyen using the CSE encrypted card blob.
     * Merchant never handles the PAN; frontend cannot fake the encrypted blob
     * because Adyen's servers must decrypt it.
     *
     * Fires two calls to Adyen and merges the results:
     *   - Checkout /cardDetails → brand (no extra role required)
     *   - BinLookup/getCostEstimate → BIN string (requires the Merchant
     *     BinLookup Webservice role; currently pending Adyen approval)
     *
     * getCostEstimate is attempted regardless — if the role hasn't been
     * granted yet it fails silently with a logged 403 and we ship without BIN.
     * Once the role is enabled, no code change is needed for BIN to start
     * flowing through.
     *
     * Returns null only if BOTH calls fail to yield a brand. Otherwise returns
     * whatever partial info we have. Never falls back to frontend values.
     */
    private function resolveCardDetailsFromAdyen(array $params, $cart): ?array
    {
        $encryptedCardNumber = $params['encrypted_card_number'] ?? null;
        if (!$encryptedCardNumber) {
            return null;
        }

        $storeId = (int) $cart->getStoreId();

        try {
            $merchantAccount = $this->adyenConfig->getMerchantAccount($storeId);
            if (!$merchantAccount) {
                $this->logger->log('Advice builder: Adyen merchant account not configured');
                return null;
            }
            $client = $this->adyenDataHelper->initializeAdyenClient($storeId);
        } catch (\Throwable $e) {
            $this->logger->log('Advice builder: Adyen client init failed. Error: ' . $e->getMessage());
            return null;
        }

        $brand = $this->fetchBrandFromCardDetails($client, $merchantAccount, $encryptedCardNumber);
        $bin = $this->fetchBinFromCostEstimate($client, $merchantAccount, $encryptedCardNumber, $cart);

        if (!$brand && !$bin) {
            return null;
        }

        return [
            'type'  => $brand,
            'last4' => null,
            'bin'   => $bin,
        ];
    }

    private function fetchBrandFromCardDetails(
        AdyenClient $client,
        string $merchantAccount,
        string $encryptedCardNumber
    ): ?string {
        try {
            $api = new PaymentsApi($client);
            $response = $api->cardDetails(new CardDetailsRequest([
                'merchantAccount'     => $merchantAccount,
                'encryptedCardNumber' => $encryptedCardNumber,
            ]));
        } catch (\Adyen\AdyenException $e) {
            $this->logger->log(sprintf(
                'Advice builder: Adyen /cardDetails failed. HTTP=%s errorType=%s errorCode=%s message=%s',
                $e->getStatus(),
                $e->getErrorType(),
                $e->getAdyenErrorCode(),
                $e->getMessage()
            ));
            return null;
        } catch (\Throwable $e) {
            $this->logger->log('Advice builder: Adyen /cardDetails failed. Error: ' . $e->getMessage());
            return null;
        }

        return $this->pickSupportedBrand($response->getBrands());
    }

    /**
     * Attempt binLookup/getCostEstimate for the authoritative BIN string.
     * Silently returns null (with a logged error) if the API user lacks the
     * BinLookup role — that's the current expected state until Adyen support
     * grants it. Once granted, this starts populating BIN with no code change.
     */
    private function fetchBinFromCostEstimate(
        AdyenClient $client,
        string $merchantAccount,
        string $encryptedCardNumber,
        $cart
    ): ?string {
        try {
            $api = new BinLookupApi($client);
            $response = $api->getCostEstimate(new CostEstimateRequest([
                'merchantAccount'     => $merchantAccount,
                'encryptedCardNumber' => $encryptedCardNumber,
                'amount'              => new BinLookupAmount([
                    'value'    => (int) round(((float) $cart->getGrandTotal()) * 100),
                    'currency' => (string) $cart->getCurrency()->getQuoteCurrencyCode(),
                ]),
            ]));
        } catch (\Adyen\AdyenException $e) {
            $this->logger->log(sprintf(
                'Advice builder: Adyen binLookup failed. HTTP=%s errorType=%s errorCode=%s message=%s',
                $e->getStatus(),
                $e->getErrorType(),
                $e->getAdyenErrorCode(),
                $e->getMessage()
            ));
            return null;
        } catch (\Throwable $e) {
            $this->logger->log('Advice builder: Adyen binLookup failed. Error: ' . $e->getMessage());
            return null;
        }

        $cardBin = $response->getCardBin();
        return $cardBin ? $cardBin->getBin() : null;
    }

    /**
     * Pick the first supported brand from /cardDetails response. Falls back to
     * the first brand if none are marked supported (rare but possible).
     */
    private function pickSupportedBrand(?array $brands): ?string
    {
        if (empty($brands)) {
            return null;
        }

        foreach ($brands as $brand) {
            if ($brand->getSupported() === true) {
                return $brand->getType();
            }
        }

        return $brands[0]->getType();
    }

    private function isAdyenGateway(?string $gateway): bool
    {
        return in_array($gateway, ['adyen', 'adyen_cc'], true);
    }
}