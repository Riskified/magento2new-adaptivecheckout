<?php
declare(strict_types=1);

namespace Riskified\AdaptiveCheckout\Plugin;

use Magento\Checkout\Model\GuestPaymentInformationManagement;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Riskified\AdaptiveCheckout\Model\AdviceResult;
use Riskified\AdaptiveCheckout\Model\Config as AdviceConfig;
use Riskified\AdaptiveCheckout\Model\PaymentMethodGate;
use Riskified\AdaptiveCheckout\Model\PlaceOrderAdyenAdviceHandler;
use Riskified\AdaptiveCheckout\Model\Security\CartStateHasher;

class GuestPaymentInformationManagementPlugin
{
    public function __construct(
        private readonly AdviceConfig $adviceConfig,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly QuoteIdMaskFactory $quoteIdMaskFactory,
        private readonly CartStateHasher $hasher,
        private readonly PaymentMethodGate $methodGate,
        private readonly PlaceOrderAdyenAdviceHandler $adyenAdviceHandler
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function beforeSavePaymentInformationAndPlaceOrder(
        GuestPaymentInformationManagement $subject,
        $cartId,
        $email,
        $paymentInformation,
        $billingAddress = null
    ): array {
        // The quote must be resolved before the config gate: `enabled` is
        // store-scoped and only the quote's own store may decide it. The
        // {store} segment of this REST route is caller-controlled.
        $quoteIdMask = $this->quoteIdMaskFactory->create()->load($cartId, 'masked_id');
        if (!$quoteIdMask->getQuoteId()) {
            return [$cartId, $email, $paymentInformation, $billingAddress];
        }

        try {
            $quote = $this->cartRepository->get($quoteIdMask->getQuoteId());
        } catch (\Throwable $e) {
            return [$cartId, $email, $paymentInformation, $billingAddress];
        }

        if (!$this->adviceConfig->isAdviceEnabledForQuote($quote)) {
            return [$cartId, $email, $paymentInformation, $billingAddress];
        }

        $method = $paymentInformation && method_exists($paymentInformation, 'getMethod')
            ? (string) $paymentInformation->getMethod()
            : '';

        if ($this->methodGate->isAdyen($method)) {
            $this->adyenAdviceHandler->handle($quote, $paymentInformation, (string) $email);
            return [$cartId, $email, $paymentInformation, $billingAddress];
        }

        if (!$this->methodGate->requiresAdvice($method)) {
            return [$cartId, $email, $paymentInformation, $billingAddress];
        }

        // Non-Adyen supported gateways still rely on the old frontend Advice
        // controller, so we verify that the frontend actually made the call
        // and hasn't tampered with the cart/email since.
        if (!AdviceResult::isProceedStatus($quote->getData('riskified_advice_status'))) {
            throw new LocalizedException(
                __('Unable to place the order because Riskified advice has not approved this checkout yet.')
            );
        }
        $this->methodGate->assertGatewayUnchanged((string) $quote->getData('riskified_advice_gateway'), $method);
        $this->validateAdviceEmailConsistency($quote, $email);
        $this->validateCartStateConsistency($quote);

        return [$cartId, $email, $paymentInformation, $billingAddress];
    }

    private function validateAdviceEmailConsistency($quote, $email): void
    {
        $adviceEmail     = $quote->getData('riskified_advice_email');
        $adviceEmailHash = $quote->getData('riskified_advice_email_hash');

        if (!$adviceEmail) {
            throw new LocalizedException(
                __('Unable to place the order. Please complete the payment verification process.')
            );
        }

        $expectedHash = $this->hasher->emailHash((string) $adviceEmail, (int) $quote->getId());
        if (!is_string($adviceEmailHash) || !hash_equals($expectedHash, $adviceEmailHash)) {
            throw new LocalizedException(
                __('Unable to place the order. Security verification failed.')
            );
        }

        if ($email !== $adviceEmail) {
            throw new LocalizedException(
                __('Unable to place the order. Payment email must match the email used for verification.')
            );
        }
    }

    private function validateCartStateConsistency($quote): void
    {
        $storedCartStateHash = $quote->getData('riskified_advice_cart_state_hash');
        $adviceEmail         = $quote->getData('riskified_advice_email');

        if (!$storedCartStateHash) {
            throw new LocalizedException(
                __('Unable to place the order. Cart validation data is missing. Please verify your order again.')
            );
        }

        $currentCartStateHash = $this->hasher->cartStateHash($quote, $adviceEmail);

        if (!hash_equals($currentCartStateHash, (string) $storedCartStateHash)) {
            throw new LocalizedException(
                __('Unable to place the order. Your cart has been modified since verification. Please verify your order again.')
            );
        }
    }
}
