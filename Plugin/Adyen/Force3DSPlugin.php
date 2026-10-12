<?php

namespace Riskified\AdaptiveCheckout\Plugin\Adyen;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Riskified\AdaptiveCheckout\Observer\CaptureForce3DSFlag;
use Riskified\Decider\Model\Api\Log;

readonly class Force3DSPlugin
{
    public function __construct(
        private Log $logger
    ) {
    }

    public function afterBuild($subject, array $result, array $buildSubject): array
    {
        try {
            $payment = SubjectReader::readPayment($buildSubject)->getPayment();
        } catch (\Throwable $e) {
            $this->logger->log('Force3DSPlugin: could not read payment: ' . $e->getMessage());
            return $result;
        }

        $flag = $payment->getAdditionalInformation(CaptureForce3DSFlag::FORCE_THREE_DS_KEY);
        $this->logger->log(sprintf(
            'Force3DSPlugin: builder=%s force_three_ds=%s',
            get_class($subject),
            var_export($flag, true)
        ));

        if ($flag !== 'true') {
            return $result;
        }

        if (!isset($result['body']) || !is_array($result['body'])) {
            $this->logger->log('Force3DSPlugin: result has no body — skipping');
            return $result;
        }

        $result['body']['authenticationData']['attemptAuthentication'] = 'always';
        $result['body']['authenticationData']['threeDSRequestData']['nativeThreeDS'] = 'preferred';

        $this->logger->log('Force3DSPlugin: injected authenticationData ' . json_encode($result['body']['authenticationData']));

        return $result;
    }
}
