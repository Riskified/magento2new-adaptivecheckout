define([
    'jquery',
    'Riskified_AdaptiveCheckout/js/model/advice',
    'PayPal_Braintree/js/view/payment/3d-secure',
    'PayPal_Braintree/js/view/payment/validator-handler',
    'Magento_Ui/js/model/messageList'
], function ($, advice, verify3DSecure, validator, messageList) {
    'use strict';

    var mixin = {
        handleNonce: function (data) {
            var self = this;
            var superMethod = this._super.bind(this);

            let generalCallback = function (message) {
                superMethod(data);
            };

            let denyCallback = function (message) {
                // Display error message to user when payment is denied
                let errorMessage = message || 'Your payment has been declined. Please try a different payment method.';
                messageList.addErrorMessage({
                    message: errorMessage
                });
                // Reset payment form state
                self.isPlaceOrderActionAllowed(true);
            };

            let disabledCallback = function (message) {
                let config = window.checkoutConfig.payment;
                window.checkoutConfig.payment[verify3DSecure.getCode()].enabled = true;
                verify3DSecure.setConfig(config[verify3DSecure.getCode()]);
                self.validatorManager.add(verify3DSecure);
                superMethod(data);
            }

            try {
                advice.additionalPayload.bin = data.details.bin;
                advice.additionalPayload.type = data.details.cardType;
                advice.additionalPayload.exp_month = data.details.expirationMonth;
                advice.additionalPayload.exp_year = data.details.expirationYear;
                advice.additionalPayload.last4 = data.details.lastFour;

                advice
                    .setGateway('braintree_cc')
                    .registerSuccessCallback(generalCallback)
                    .registerScaCallback(disabledCallback)
                    .registerDenyCallback(denyCallback)
                    .registerDisabledCallback(disabledCallback)
                    .registerFailedCallback(disabledCallback)
                    .validate();

                // Return false to prevent immediate execution, callbacks will handle the flow
                return false;
            } catch (e) {
                return false;
            }

            this._super(data);
        }
    };

    return function (target) {
        return target.extend(mixin);
    };
});
