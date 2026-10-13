<?php
declare(strict_types=1);

namespace Riskified\AdaptiveCheckout\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Store\Model\ScopeInterface;

readonly class Config
{
    private const string XML_ADVICE_ENABLED = 'riskified/riskified_advise_process/enabled';

    public function __construct(private ScopeConfigInterface $scopeConfig)
    {
    }

    public function isAdviceEnabledForQuote(?CartInterface $quote): bool
    {
        return $this->isAdviceEnabledForStore($quote?->getStoreId());
    }

    public function isAdviceEnabledForStore(int|string|null $storeId): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_ADVICE_ENABLED,
            ScopeInterface::SCOPE_STORE,
            ($storeId === null || $storeId === '') ? null : (int) $storeId
        );
    }
}
