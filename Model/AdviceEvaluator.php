<?php
declare(strict_types=1);

namespace Riskified\AdaptiveCheckout\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Registry;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Riskified\AdaptiveCheckout\Model\Builder\Advice as AdviceBuilder;
use Riskified\AdaptiveCheckout\Model\Security\CartStateHasher;
use Riskified\AdaptiveCheckout\Observer\InvalidateAdviceOnQuoteChange;
use Riskified\Decider\Model\Api\Api;
use Riskified\Decider\Model\Api\Log;
use Riskified\Decider\Model\Api\Order\Helper as DeciderOrderHelper;

class AdviceEvaluator
{
    private const string ACTION_PROCEED  = 'proceed';
    private const string ACTION_DECLINED = 'declined';
    private const string ACTION_DECLINE  = 'decline';
    private const string REC_SCA         = 'sca';
    private const string REC_TRA         = 'tra';
    private const string TYPE_PSD2       = 'psd2';

    public function __construct(
        private readonly Config $config,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CartStateHasher $hasher,
        private readonly AdviceBuilder $adviceBuilder,
        private readonly Api $api,
        private readonly Log $logger,
        private readonly Registry $registry,
        private readonly DeciderOrderHelper $deciderOrderHelper,
        private readonly CheckoutSession $checkoutSession,
        private readonly PaymentMethodGate $methodGate,
    ) {}

    /**
     * Resolved against the quote's own store — never the request's. See
     * Riskified\AdaptiveCheckout\Model\Config for why.
     */
    public function isEnabled(CartInterface $quote): bool
    {
        return $this->config->isAdviceEnabledForQuote($quote);
    }

    public function evaluate(CartInterface $quote, string $gateway, array $extraPayload = []): AdviceResult
    {
        if (!$this->isEnabled($quote)) {
            return new AdviceResult(AdviceResult::STATUS_PROCEED_TRA);
        }

        $email = trim((string) $quote->getCustomerEmail());
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->logger->log('AdviceEvaluator: quote has no valid customer email; quote=' . $quote->getId());
            return new AdviceResult(AdviceResult::STATUS_ERROR, 'Missing customer email');
        }

        $params = array_merge(
            ['gateway' => $gateway, 'quote_id' => $quote->getId()],
            $extraPayload
        );

        try {
            $this->deciderOrderHelper->setCheckoutSession($this->checkoutSession);
            $this->api->initSdk($quote);
            $this->adviceBuilder->buildForQuote($quote, $params);
            $callResponse = $this->adviceBuilder->request();
        } catch (\Riskified\OrderWebhook\Exception\CurlException $e) {
            $this->logger->log('AdviceEvaluator: transport error: ' . $e->getMessage());
            return new AdviceResult(AdviceResult::STATUS_ERROR, 'Unable to connect to Riskified service');
        } catch (\Riskified\OrderWebhook\Exception\UnsuccessfulActionException $e) {
            $this->logger->log('AdviceEvaluator: API error: ' . $e->getMessage());
            return new AdviceResult(AdviceResult::STATUS_ERROR, 'Service temporarily unavailable');
        } catch (\Throwable $e) {
            $this->logger->log('AdviceEvaluator: unexpected error: ' . $e->getMessage());
            return new AdviceResult(AdviceResult::STATUS_ERROR, 'An unexpected error occurred');
        }

        if (!isset($callResponse->checkout)) {
            $this->logger->log('AdviceEvaluator: malformed response: ' . json_encode($callResponse));
            return new AdviceResult(AdviceResult::STATUS_ERROR);
        }

        $result = $this->interpret($callResponse);

        $this->persistAdviceData($quote, $result, $email, $gateway);

        return $result;
    }

    /**
     * Both hashes are derived here rather than before the advice call: the call
     * itself is read-only on the quote (the builder and the Decider order helper
     * only read from it), so the cart state is identical either way, and an
     * errored call leaves the quote untouched.
     */
    private function persistAdviceData(
        CartInterface $quote,
        AdviceResult $result,
        string $email,
        string $gateway
    ): void {
        $quote->setData('riskified_advice_status', $result->status);
        $quote->setData('riskified_advice_email', $email);
        $quote->setData(
            'riskified_advice_cart_state_hash',
            $this->hasher->cartStateHash($quote, $email)
        );
        $quote->setData(
            'riskified_advice_gateway',
            $this->methodGate->canonicalGateway($gateway)
        );
        $quote->setData(
            'riskified_advice_email_hash',
            $this->hasher->emailHash($email, (int) $quote->getId())
        );

        try {
            $this->saveQuoteInternally($quote);
        } catch (\Throwable $e) {
            $this->logger->log('AdviceEvaluator: failed to persist advice data for quote ' . $quote->getId() . ': ' . $e->getMessage());
        }
    }

    private function interpret(object $callResponse): AdviceResult
    {
        $checkout = $callResponse->checkout;
        $description = $checkout->description ?? null;

        if (isset($checkout->action)) {
            // Riskified returns "decline" (singular) for hard declines; older
            // paths use "declined". Treat both the same.
            return match ($checkout->action) {
                // A proceed can still carry a PSD2 SCA/TRA recommendation —
                // honour it so 3DS is forced when Riskified asks; otherwise
                // fall back to a plain proceed.
                self::ACTION_PROCEED  => $this->interpretRecommendations($checkout)
                    ?? new AdviceResult(AdviceResult::STATUS_PROCEED, $description),
                self::ACTION_DECLINE,
                self::ACTION_DECLINED => new AdviceResult(AdviceResult::STATUS_DECLINE, $description),
                default               => new AdviceResult(AdviceResult::STATUS_ERROR, $description),
            };
        }

        return $this->interpretRecommendations($checkout)
            ?? new AdviceResult(AdviceResult::STATUS_PROCEED_TRA);
    }

    /**
     * Map the first PSD2 authentication recommendation to a result, or null
     * when the response carries no applicable PSD2 recommendation.
     */
    private function interpretRecommendations(object $checkout): ?AdviceResult
    {
        foreach ($checkout->advice->recommendations ?? [] as $auth) {
            if (($auth->type ?? null) !== self::TYPE_PSD2) {
                continue;
            }
            if (($auth->recommendation ?? null) === self::REC_SCA) {
                return new AdviceResult(AdviceResult::STATUS_SCA, 'Enabled SCA');
            }
            if (($auth->recommendation ?? null) === self::REC_TRA) {
                return new AdviceResult(AdviceResult::STATUS_PROCEED_TRA, 'Enabled TRA');
            }
            return new AdviceResult(AdviceResult::STATUS_PROCEED_TRA, 'Out of scope');
        }

        return null;
    }

    private function saveQuoteInternally(CartInterface $quote): void
    {
        $this->registry->register(InvalidateAdviceOnQuoteChange::INTERNAL_SAVE_FLAG, true, true);
        try {
            $this->cartRepository->save($quote);
        } finally {
            $this->registry->unregister(InvalidateAdviceOnQuoteChange::INTERNAL_SAVE_FLAG);
        }
    }
}
