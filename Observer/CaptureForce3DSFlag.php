<?php

namespace Riskified\AdaptiveCheckout\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Riskified\Decider\Model\Api\Log;

class CaptureForce3DSFlag implements ObserverInterface
{
    public const string FORCE_THREE_DS_KEY = 'force_three_ds';

    public function __construct(private readonly Log $logger) {}

    public function execute(Observer $observer): void
    {
        $data = $observer->getEvent()->getDataByKey('data');
        $paymentInfo = $observer->getEvent()->getDataByKey('payment_model');

        if (!$data || !$paymentInfo) {
            $this->logger->log('CaptureForce3DSFlag: missing data or payment_model — event=' . $observer->getEvent()->getName());
            return;
        }

        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        $allData = $data->getData();
        $this->logger->log(sprintf(
            'CaptureForce3DSFlag: event=%s all_data_keys=%s additional_data=%s',
            $observer->getEvent()->getName(),
            is_array($allData) ? implode(',', array_keys($allData)) : gettype($allData),
            is_array($additionalData) ? json_encode(array_keys($additionalData)) : var_export($additionalData, true)
        ));

        if (!is_array($additionalData)) {
            return;
        }

        if (!empty($additionalData[self::FORCE_THREE_DS_KEY])
            && $additionalData[self::FORCE_THREE_DS_KEY] === 'true'
        ) {
            $paymentInfo->setAdditionalInformation(self::FORCE_THREE_DS_KEY, 'true');
            $this->logger->log('CaptureForce3DSFlag: flag set on payment info');
        }
    }
}
