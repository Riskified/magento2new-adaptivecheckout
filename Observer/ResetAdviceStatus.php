<?php
declare(strict_types=1);

namespace Riskified\AdaptiveCheckout\Observer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Riskified\AdaptiveCheckout\Model\Config as AdviceConfig;

class ResetAdviceStatus implements ObserverInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly AdviceConfig $adviceConfig
    ) {}

    public function execute(Observer $observer): void
    {
        $quote = $this->checkoutSession->getQuote();
        if (!$quote || !$quote->getId()) {
            return;
        }

        // Gate on the quote's own store — see Riskified\AdaptiveCheckout\Model\Config.
        if (!$this->adviceConfig->isAdviceEnabledForQuote($quote)) {
            return;
        }

        $status = (string)$quote->getData('riskified_advice_status');
        if ($status === '') {
            return;
        }

        // Log the advice status reset for security audit trail
        error_log('Riskified Advice: Resetting advice status for quote ID: ' . $quote->getId() .
                 '. Previous status: ' . $status . '. Trigger: checkout index page access');

        $quote->setData('riskified_advice_status', null);
        $quote->setData('riskified_advice_email', null);
        $quote->setData('riskified_advice_email_hash', null);
        $quote->setData('riskified_advice_cart_state_hash', null);
        $quote->setData('riskified_advice_gateway', null);
        $this->cartRepository->save($quote);
    }
}
