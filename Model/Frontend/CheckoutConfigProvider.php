<?php
declare(strict_types=1);

namespace Riskified\AdaptiveCheckout\Model\Frontend;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\QuoteIdMaskFactory;

/**
 * Exposes the masked quote id to the storefront under
 * `window.checkoutConfig.riskifiedAdvice.maskedQuoteId`. The Advice JS uses
 * this instead of `quote.getQuoteId()` — core's value is the raw entity_id
 * for logged-in customers, and the Advice controllers (correctly) refuse
 * numeric ids. Without this provider, logged-in customers can't complete an
 * advice-gated checkout.
 *
 * For customer carts that never had a mask record created, this lazily
 * creates one so the storefront and the controllers see the same id.
 */
class CheckoutConfigProvider implements ConfigProviderInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly QuoteIdMaskFactory $quoteIdMaskFactory
    ) {
    }

    public function getConfig(): array
    {
        $quoteId = (int) $this->checkoutSession->getQuoteId();
        if ($quoteId === 0) {
            return [];
        }

        $mask = $this->quoteIdMaskFactory->create()->load($quoteId, 'quote_id');
        if (!$mask->getMaskedId()) {
            $mask->setQuoteId($quoteId)->save();
        }

        return [
            'riskifiedAdvice' => [
                'maskedQuoteId' => (string) $mask->getMaskedId(),
            ],
        ];
    }
}
