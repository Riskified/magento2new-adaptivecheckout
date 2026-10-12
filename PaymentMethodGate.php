<?php
declare(strict_types=1);

namespace Riskified\AdaptiveCheckout\Model;

use Magento\Framework\Exception\LocalizedException;

/**
 * Tells the order-placement gates whether the customer's payment method goes
 * through the Advice JS flow. Methods outside this set (PayPal, COD, custom
 * gateways, etc.) never trigger the advice call, so they must be exempted
 * from the "missing advice status" block — otherwise we lock out every order
 * placed with an unsupported method.
 *
 * Keep this list aligned with the frontend mixins wired in
 * view/frontend/requirejs-config.js plus any setGateway() calls in
 * view/frontend/web/js/view/payment/**.
 */
class PaymentMethodGate
{
    private const array SUPPORTED_METHODS = [
        'adyen_cc',
        'adyen_cc_vault',
        'adyen_oneclick',
        'braintree',
        'braintree_cc_vault',
    ];

    /**
     * Adyen methods run Advice server-side inside the place-order plugins.
     * Everything else in SUPPORTED_METHODS still goes through the old
     * frontend controller flow until it migrates.
     */
    private const array ADYEN_METHODS = [
        'adyen_cc',
        'adyen_cc_vault',
        'adyen_oneclick',
    ];

    private const array GATEWAY_FAMILIES = [
        'adyen',
        'braintree',
    ];

    public function canonicalGateway(?string $gatewayOrMethod): string
    {
        $value = strtolower(trim((string) $gatewayOrMethod));
        if ($value === '') {
            return '';
        }

        foreach (self::GATEWAY_FAMILIES as $family) {
            if ($value === $family || str_starts_with($value, $family . '_')) {
                return $family;
            }
        }

        return $value;
    }

    /**
     * Confirms the decision stored on the quote was issued for the same gateway
     * family the order is now being placed with.
     *
     * AdviceEvaluator::persistAdviceData writes riskified_advice_gateway and
     * riskified_advice_status together, atomically, so a quote with a non-empty
     * status but an empty stored gateway can only be one whose advice decision
     * predates that column — not a gateway switch. Callers only reach this
     * method once status is confirmed non-empty, so we let those pre-existing
     * quotes through rather than blocking an already-approved in-flight order.
     *
     * @throws LocalizedException when the method changed since the advice call
     */
    public function assertGatewayUnchanged(?string $storedGateway, ?string $currentMethod): void
    {
        $stored = $this->canonicalGateway($storedGateway);
        if ($stored === '') {
            return;
        }

        if ($stored !== $this->canonicalGateway($currentMethod)) {
            throw new LocalizedException(
                __('Unable to place the order. The payment method changed since verification. Please verify your order again.')
            );
        }
    }

    public function requiresAdvice(?string $paymentMethod): bool
    {
        if ($paymentMethod === null || $paymentMethod === '') {
            return false;
        }
        return in_array($paymentMethod, self::SUPPORTED_METHODS, true);
    }

    public function isAdyen(?string $paymentMethod): bool
    {
        if ($paymentMethod === null || $paymentMethod === '') {
            return false;
        }
        return in_array($paymentMethod, self::ADYEN_METHODS, true);
    }
}
