# magento2new-adaptivecheckout

Magento 2 module for [Riskified Adaptive Checkout](https://developers.riskified.com/docs/adaptive-checkout). Before a payment is authorized, it asks Riskified for an "advice" decision on the cart and enforces that decision when the order is placed.

Module name: `Riskified_AdaptiveCheckout`. Composer package: `riskified/magento2new-adaptivecheckout`.

## Requirements

- PHP 8.3 or later
- `riskified/magento2new` (`Riskified_Decider`) and `riskified/php_sdk`

## How it works

1. During checkout, `AdviceEvaluator` builds an advice request from the quote and sends it to Riskified, tagged with the payment gateway.
2. The response is mapped to an `AdviceResult`:

   | Riskified response | Result |
   | --- | --- |
   | `proceed` | Proceed |
   | `proceed` or no action, with a PSD2 `tra` recommendation | Proceed with TRA exemption |
   | `proceed` or no action, with a PSD2 `sca` recommendation | SCA: force 3DS |
   | `decline` / `declined` | Decline |
   | Transport or API error, malformed response, unknown action, or no valid customer email | Error |

3. The result is stored on the quote (see [Data model](#data-model)), along with an HMAC of the cart state and of the email. The HMAC key is derived from Magento's crypt key (`Model/Security/CartStateHasher`).
4. At order placement, the place-order plugins (`PaymentInformationManagementPlugin`, `GuestPaymentInformationManagementPlugin`) and `Observer/PreventOrderPlacement` block the order if:
   - the advice status is missing, declined or an error,
   - the cart state or email changed after the advice call, or
   - the payment gateway changed since the advice call.
5. Quote-change observers (`InvalidateAdviceOnQuoteChange`, `ResetAdviceStatus`) clear the stored advice, which forces a new check.
6. On an SCA result for Adyen, `Plugin/Adyen/Force3DSPlugin` sets `attemptAuthentication=always` and `nativeThreeDS=preferred` on the Adyen request so that 3DS is enforced.

## Supported payment methods

`Model/PaymentMethodGate` defines which methods go through the advice flow. Any other method (PayPal, COD, custom gateways) skips it and is never blocked for missing advice.

| Gateway | Methods | Where advice runs |
| --- | --- | --- |
| Adyen | `adyen_cc`, `adyen_cc_vault`, `adyen_oneclick` | Server-side, in the place-order plugins (`PlaceOrderAdyenAdviceHandler`) |
| Braintree | `braintree`, `braintree_cc_vault` | Frontend flow: `advice/*` controllers (`Call`, `Deny`) and the JS mixins in `view/frontend/web/js` |

Keep `PaymentMethodGate` in sync with the mixins in `view/frontend/requirejs-config.js`.

## Configuration

Stores > Configuration > Riskified > **Advice process** > **Enabled** (default, website and store scope).

The setting is resolved against the quote's own store, not the request's (`Model/Config`).

## Data model

`etc/db_schema.xml` adds these columns to the `quote` table:

| Column | Purpose |
| --- | --- |
| `riskified_advice_status` | Advice result |
| `riskified_advice_email` | Email the advice was issued for |
| `riskified_advice_email_hash` | Email hash used for validation |
| `riskified_advice_cart_state_hash` | Cart state hash used to detect changes |
| `riskified_advice_gateway` | Gateway family the decision was issued for |

## Layout

```
Controller/Advice/      Frontend endpoints (Call, Deny) for the Braintree flow
Model/                  AdviceEvaluator, AdviceResult, PaymentMethodGate, Config, request builder
Model/Security/         CartStateHasher
Observer/               Order-placement guard, advice invalidation, 3DS flag capture
Plugin/                 Place-order enforcement, Adyen 3DS forcing, Adyen vault BIN backfill
view/frontend/web/js/   Checkout mixins for Braintree and card forms
etc/                    module, DI, events, routes, admin config, DB schema
```
