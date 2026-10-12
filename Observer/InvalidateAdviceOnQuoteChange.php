<?php
declare(strict_types=1);

namespace Riskified\AdaptiveCheckout\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Registry;
use Riskified\AdaptiveCheckout\Model\Config as AdviceConfig;
use Riskified\AdaptiveCheckout\Model\Security\CartStateHasher;

class InvalidateAdviceOnQuoteChange implements ObserverInterface
{
    /**
     * Registry key set by Riskified_AdaptiveCheckout's own controllers around their
     * cartRepository->save() calls. When present, this observer must not
     * invalidate — otherwise it wipes the data the controller just stored.
     */
    public const INTERNAL_SAVE_FLAG = 'riskified_advice_internal_save';

    public function __construct(
        private readonly AdviceConfig $adviceConfig,
        private readonly Registry $registry,
        private readonly CartStateHasher $hasher
    ) {
    }

    public function execute(Observer $observer): void
    {
        // Skip when the save was initiated by our own controller — otherwise
        // we'd recompute the cart hash mid-save (after collectTotals has run)
        // and invalidate the data the controller is in the middle of storing.
        if ($this->registry->registry(self::INTERNAL_SAVE_FLAG)) {
            return;
        }

        $quote = $observer->getEvent()->getQuote();
        if (!$quote || !$quote->getId()) {
            return;
        }

        // Gate on the quote's own store — see Riskified\AdaptiveCheckout\Model\Config.
        if (!$this->adviceConfig->isAdviceEnabledForQuote($quote)) {
            return;
        }

        $adviceStatus = $quote->getData('riskified_advice_status');
        if ($adviceStatus === null || $adviceStatus === '') {
            return;
        }

        $storedCartStateHash = $quote->getData('riskified_advice_cart_state_hash');
        $adviceEmail         = $quote->getData('riskified_advice_email');

        if (!$storedCartStateHash || !$adviceEmail) {
            $this->invalidateAdviceStatus($quote);
            return;
        }

        $currentCartStateHash = $this->hasher->cartStateHash($quote, $adviceEmail);

        if (!hash_equals($currentCartStateHash, (string)$storedCartStateHash)) {
            $this->invalidateAdviceStatus($quote);
            return;
        }

        $currentEmail = $quote->getCustomerEmail();
        if ($currentEmail !== $adviceEmail) {
            $this->invalidateAdviceStatus($quote);
        }
    }

    /**
     * Clear advice data on the in-memory quote. No save() here — we run inside
     * quote_save_before, so the outer save persists the cleared values. A
     * recursive save here used to cause self-inflicted invalidations during
     * the controller's own writes.
     */
    private function invalidateAdviceStatus($quote): void
    {
        $quote->setData('riskified_advice_status', null);
        $quote->setData('riskified_advice_email', null);
        $quote->setData('riskified_advice_email_hash', null);
        $quote->setData('riskified_advice_cart_state_hash', null);
        $quote->setData('riskified_advice_gateway', null);
    }
}