<?php
declare(strict_types=1);

namespace Riskified\AdaptiveCheckout\Plugin;

use Magento\Checkout\Model\PaymentInformationManagement;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Riskified\AdaptiveCheckout\Model\AdviceResult;
use Riskified\AdaptiveCheckout\Model\Config as AdviceConfig;
use Riskified\AdaptiveCheckout\Model\PaymentMethodGate;
use Riskified\AdaptiveCheckout\Model\PlaceOrderAdyenAdviceHandler;
use Riskified\AdaptiveCheckout\Model\Security\CartStateHasher;

/**
 * Customer (logged-in) counterpart of GuestPaymentInformationManagementPlugin.
 * Same behavior, different subject class.
 */
class PaymentInformationManagementPlugin
{
    public function __construct(
        private readonly AdviceConfig $adviceConfig,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CartStateHasher $hasher,
        private readonly PaymentMethodGate $methodGate,
        private readonly PlaceOrderAdyenAdviceHandler $adyenAdviceHandler
    ) {
    }

    public function beforeSavePaymentInformationAndPlaceOrder(
        PaymentInformationManagement $subject,
        $cartId,
        $paymentInformation,
        $billingAddress = null
    ): array {
        // The quote must be resolved before the config gate: `enabled` is
        // store-scoped and only the quote's own store may decide it. The
        // {store} segment of this REST route is caller-controlled.
        try {
            $quote = $this->cartRepository->get((int) $cartId);
        } catch (\Throwable $e) {
            return [$cartId, $paymentInformation, $billingAddress];
        }

        if (!$this->adviceConfig->isAdviceEnabledForQuote($quote)) {
            return [$cartId, $paymentInformation, $billingAddress];
        }

        $method = $paymentInformation && method_exists($paymentInformation, 'getMethod')
            ? (string) $paymentInformation->getMethod()
            : '';

        if ($this->methodGate->isAdyen($method)) {
            $email = (string) $quote->getCustomerEmail();
            $this->adyenAdviceHandler->handle($quote, $paymentInformation, $email);
            return [$cartId, $paymentInformation, $billingAddress];
        }

        if (!$this->methodGate->requiresAdvice($method)) {
            return [$cartId, $paymentInformation, $billingAddress];
        }

        if (!AdviceResult::isProceedStatus($quote->getData('riskified_advice_status'))) {
            throw new LocalizedException(
                __('Unable to place the order because Riskified advice has not approved this checkout yet.')
            );
        }

        $this->methodGate->assertGatewayUnchanged((string) $quote->getData('riskified_advice_gateway'), $method);

        $storedCartStateHash = $quote->getData('riskified_advice_cart_state_hash');
        $adviceEmail         = (string) $quote->getData('riskified_advice_email');
        if (!$storedCartStateHash || $adviceEmail === '') {
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

        return [$cartId, $paymentInformation, $billingAddress];
    }
}
