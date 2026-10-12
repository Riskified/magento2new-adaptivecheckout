define([
    'jquery',
    'mage/utils/wrapper',
    'mage/storage',
    'PayPal_Braintree/js/view/payment/3d-secure',
    'Magento_Checkout/js/model/quote',
    'Riskified_AdaptiveCheckout/js/model/advice',
    'mage/translate',
], function ($, wrapper, storage, verify3DSecure, quote, advice, $t) {
    'use strict';


    function getPaymentMethod()
    {
        let choosenPaymentMethod = $(".payment-method-title").find('input[type="radio"]:checked');
        return choosenPaymentMethod.attr('id');
    }

    return function (braintreeValidatorHandler) {
        braintreeValidatorHandler.validate = function(context, callback) {
            var self = this,
                config = this.getConfig();

            /**
             * Run the queued Braintree validators and only invoke the checkout
             * callback once every one of them resolves.
             */
            function runValidators() {
                var deferred = $.map(self.validators, function (current) {
                    return current.validate(context);
                });

                $.when.apply($, deferred)
                    .done(function () {
                        callback();
                    }).fail(function (error) {
                    self.showError(error);
                });
            }

            // When there are no validators yet, the (vaulted) Braintree card
            // must clear a Riskified advice decision before checkout proceeds.
            // advice.validate() resolves asynchronously via AJAX, so the whole
            // downstream flow has to run *inside* its callbacks — otherwise it
            // would fall through and call callback() before advice resolves,
            // defeating the gate.
            if (!self.validators.length) {
                let approveCallback = function () {
                    // Advice approved: continue checkout.
                    runValidators();
                };

                let denyCallback = function () {
                    // Advice declined: block checkout, don't call callback().
                    self.showError($t('Your payment could not be verified. Please try again with another form of payment.'));
                };

                let disabledCallback = function () {
                    // Advice disabled or errored: fall back to native 3D Secure.
                    verify3DSecure.setConfig(config[verify3DSecure.getCode()]);
                    self.add(verify3DSecure);
                    runValidators();
                };

                try {
                    advice
                        .setGateway('braintree_cc')
                        .registerSuccessCallback(approveCallback)
                        .registerScaCallback(disabledCallback)
                        .registerDenyCallback(denyCallback)
                        .registerDisabledCallback(disabledCallback)
                        .registerFailedCallback(disabledCallback)
                        .validate();
                } catch (e) {
                    return false;
                }

                return;
            }

            runValidators();
        };

        return braintreeValidatorHandler;
    };
});
