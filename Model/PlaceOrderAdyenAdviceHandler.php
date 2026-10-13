<?php
declare(strict_types=1);

namespace Riskified\AdaptiveCheckout\Model;

use Adyen\Payment\Model\ResourceModel\StateData\Collection as StateDataCollection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Riskified\AdaptiveCheckout\Observer\CaptureForce3DSFlag;
use Riskified\Decider\Model\Api\Log;

readonly class PlaceOrderAdyenAdviceHandler
{
    public function __construct(
        private AdviceEvaluator     $evaluator,
        private PaymentMethodGate   $methodGate,
        private Log                 $logger,
        private StateDataCollection $stateDataCollection,
    ) {}

    /**
     * @throws LocalizedException on Advice decline
     */
    public function handle(CartInterface $quote, PaymentInterface $paymentInformation, string $email): void
    {
        $method = (string) $paymentInformation->getMethod();
        if (!$this->methodGate->isAdyen($method)) {
            return;
        }

        if (!$this->evaluator->isEnabled($quote)) {
            return;
        }

        if (trim((string) $quote->getCustomerEmail()) === '') {
            $quote->setCustomerEmail($email);
        }

        $extraPayload = $this->extractCardPayload($method, $paymentInformation, $quote);

        $result = $this->evaluator->evaluate($quote, $method, $extraPayload);

        if ($result->isDeclined()) {
            $this->logger->log(sprintf(
                'PlaceOrderAdyenAdviceHandler: Advice declined quote=%s method=%s',
                $quote->getId(),
                $method
            ));
            throw new LocalizedException(
                __('Your payment has been declined. Please try a different payment method.')
            );
        }

        if ($result->forceSca()) {
            $this->injectForce3DSFlag($paymentInformation);
            $this->logger->log(sprintf(
                'PlaceOrderAdyenAdviceHandler: force_three_ds set quote=%s method=%s',
                $quote->getId(),
                $method
            ));
        }
    }

    private function extractCardPayload(string $method, PaymentInterface $paymentInformation, CartInterface $quote): array
    {
        $additionalData = $paymentInformation->getAdditionalData() ?? [];

        if ($method === 'adyen_cc') {
            $stateData = $this->readStateData($additionalData, $quote);
            $encrypted = $stateData['paymentMethod']['encryptedCardNumber'] ?? null;
            if (is_string($encrypted) && $encrypted !== '') {
                return ['encrypted_card_number' => $encrypted];
            }
            return [];
        }

        $publicHash = $additionalData['public_hash'] ?? null;
        if (is_string($publicHash) && $publicHash !== '') {
            return ['public_hash' => $publicHash];
        }
        return [];
    }

    private function readStateData(array $additionalData, CartInterface $quote): array
    {
        $inline = $additionalData['stateData'] ?? null;
        if (is_string($inline) && $inline !== '') {
            $decoded = json_decode($inline, true);
            if (is_array($decoded) && !empty($decoded)) {
                return $decoded;
            }
        }

        try {
            return $this->stateDataCollection->getStateDataArrayWithQuoteId((int) $quote->getId());
        } catch (\Throwable $e) {
            $this->logger->log('PlaceOrderAdyenAdviceHandler: state-data lookup failed: ' . $e->getMessage());
            return [];
        }
    }

    private function injectForce3DSFlag(PaymentInterface $paymentInformation): void
    {
        $additionalData = $paymentInformation->getAdditionalData() ?? [];
        if (!is_array($additionalData)) {
            $additionalData = [];
        }
        $additionalData[CaptureForce3DSFlag::FORCE_THREE_DS_KEY] = 'true';
        $paymentInformation->setAdditionalData($additionalData);
    }
}
