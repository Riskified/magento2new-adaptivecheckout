<?php
declare(strict_types=1);

namespace Riskified\AdaptiveCheckout\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Riskified\AdaptiveCheckout\Model\AdviceResult;
use Riskified\AdaptiveCheckout\Model\Config as AdviceConfig;
use Riskified\AdaptiveCheckout\Model\PaymentMethodGate;
use Riskified\AdaptiveCheckout\Model\Security\CartStateHasher;

class PreventOrderPlacement implements ObserverInterface
{
    public function __construct(
        private readonly AdviceConfig $adviceConfig,
        private readonly CartStateHasher $hasher,
        private readonly PaymentMethodGate $methodGate
    ) {
    }

    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getQuote();
        if (!$quote) {
            return;
        }

        // Gate on the quote's own store, not the request's — the {store}
        // segment of the checkout REST routes is caller-controlled, and
        // resolving `enabled` against it would let a cart built on a store
        // with Advice on be ordered through a store with Advice off.
        if (!$this->adviceConfig->isAdviceEnabledForQuote($quote)) {
            return;
        }

        $rawStatus = $quote->getData('riskified_advice_status');

        $paymentMethod = $quote->getPayment() ? (string) $quote->getPayment()->getMethod() : '';
        if ($this->methodGate->requiresAdvice($paymentMethod)) {
            if (!AdviceResult::isProceedStatus($rawStatus)) {
                throw new LocalizedException(
                    __('Unable to place the order because Riskified advice has not approved this checkout yet.')
                );
            }

            $this->methodGate->assertGatewayUnchanged((string) $quote->getData('riskified_advice_gateway'), $paymentMethod);
            $this->validateAdviceEmailConsistency($quote);
            $this->validateCartStateConsistency($quote);
            return;
        }

        // Method doesn't need advice, but a leftover non-proceed status still
        // blocks — switching away from a gated method after a decline is a known
        // bypass. No status means advice never ran for this quote, so let it through.
        $hasStatus = $rawStatus !== null && $rawStatus !== '';
        if ($hasStatus && !AdviceResult::isProceedStatus($rawStatus)) {
            throw new LocalizedException(
                __('Unable to place the order because Riskified advice has not approved this checkout yet.')
            );
        }
    }

    /**
     * Confirms the email on the quote matches the one advice was approved against
     * and that the stored hash hasn't been tampered with.
     */
    private function validateAdviceEmailConsistency($quote): void
    {
        $adviceEmail     = $quote->getData('riskified_advice_email');
        $currentEmail    = $quote->getCustomerEmail();
        $adviceEmailHash = $quote->getData('riskified_advice_email_hash');

        if (!$adviceEmail) {
            throw new LocalizedException(
                __('Unable to place the order. Please complete the payment verification process.')
            );
        }

        $expectedHash = $this->hasher->emailHash((string)$adviceEmail, (int)$quote->getId());
        if (!is_string($adviceEmailHash) || !hash_equals($expectedHash, $adviceEmailHash)) {
            throw new LocalizedException(
                __('Unable to place the order. Security verification failed.')
            );
        }

        if ($currentEmail !== $adviceEmail) {
            throw new LocalizedException(
                __('Unable to place the order. Payment email must match the email used for verification.')
            );
        }
    }

    /**
     * Confirms the cart hasn't changed since advice was approved.
     */
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

        if (!hash_equals($currentCartStateHash, (string)$storedCartStateHash)) {
            throw new LocalizedException(
                __('Unable to place the order. Your cart has been modified since verification. Please verify your order again.')
            );
        }
    }
}
