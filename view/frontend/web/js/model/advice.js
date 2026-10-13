/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
/**
 * @api
 */
define([
    'jquery',
    'Magento_Checkout/js/model/quote',
    'mage/url',
    'mage/cookies'
], function ($, quote, urlBuilder) {
    'use strict';

    return {
        payload : {},
        additionalPayload : {},
        valid : true,
        setGateway: function(gateway) {
          this.gateway = gateway;

          return this;
        },
        setMode: function(mode) {
          this.mode = mode;

            return this;
        },
        preparePayload : function() {
            // Always send the masked quote id. Magento core ships the raw
            // entity_id for logged-in customers via quote.getQuoteId(), which
            // the Advice controllers refuse — see Model/Frontend/CheckoutConfigProvider.
            const maskedQuoteId = (window.checkoutConfig
                && window.checkoutConfig.riskifiedAdvice
                && window.checkoutConfig.riskifiedAdvice.maskedQuoteId) || '';

            const payload = {
                form_key: $.mage.cookies.get('form_key'),
                quote_id: maskedQuoteId,
                email: quote.guestEmail,
                gateway: this.gateway
            };

            this.payload = {
                ...payload,
                ...this.additionalPayload
            };
        },
        setAdditionalPayload : function(payload) {
            this.additionalPayload = payload;

            return this;
        },
        registerSuccessCallback : function(callback) {
            this.successCallback = callback;

            return this;
        },
        registerDenyCallback : function(callback) {
            this.denyCallback = callback;

            return this;
        },
        registerFailedCallback : function(callback) {
            this.failedCallback = callback;

            return this;
        },
        registerDisabledCallback : function(callback) {
            this.disableCallback = callback;

            return this;
        },
        registerScaCallback : function(callback) {
            this.scaCallback = callback;

            return this;
        },
        validate : function() {
            this.preparePayload();

            return this.doCall("advice/advice/call", this.payload);
        },
        deny : function() {
            this.preparePayload();
            return this.doCall("advice/advice/deny", this.payload);
        },
        doCall : function(url, payload) {
            const self = this;
            return $.ajax({
                url: urlBuilder.build(url),
                type: 'POST',
                data: $.param(payload), // Convert to form-encoded format
                contentType: "application/x-www-form-urlencoded; charset=utf-8"
            }).fail(function (xhr, status, error) {
                console.error('Riskified Advice API call failed:', error);
                self.valid = false;
            }).done(function (response) {
                const apiResponseStatus = response.status;
                const message = response.message || '';
                if (apiResponseStatus === 0) {
                    if (self.scaCallback) {
                        self.scaCallback(message, apiResponseStatus);
                    } else if (self.disableCallback) {
                        self.disableCallback(message, apiResponseStatus);
                    }
                    self.valid = true;
                } else {
                    if (apiResponseStatus === 3) {
                        if (self.denyCallback) {
                            self.denyCallback(message, apiResponseStatus);
                        }
                        self.valid = false;
                    } else if (apiResponseStatus === 9999) {
                        if (self.disableCallback) {
                            self.disableCallback(message, apiResponseStatus);
                        }
                        self.valid = false;
                    } else if (apiResponseStatus === 1) {
                        if (self.failedCallback) {
                            self.failedCallback(message, apiResponseStatus);
                        }
                        self.valid = false;
                    } else {
                        if (self.successCallback) {
                            self.successCallback(message, apiResponseStatus);
                        }
                        self.valid = true;
                    }
                }
            });
        }
    };
});
