<?php

namespace Riskified\AdaptiveCheckout\Plugin;

use Adyen\Payment\Helper\Vault as AdyenVault;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Riskified\Decider\Model\Api\Log;

/**
 * Adyen's vault helper persists brand/last4/expiry into token details but not
 * the BIN, which Riskified Advice needs for pre-authorization risk scoring.
 * The BIN is available on the payment's additionalData at tokenization time,
 * so capture it here and add it to the token's details.
 */
class AdyenVaultBinBackfillPlugin
{
    private PaymentTokenRepositoryInterface $paymentTokenRepository;
    private Log $logger;

    public function __construct(
        PaymentTokenRepositoryInterface $paymentTokenRepository,
        Log $logger
    ) {
        $this->paymentTokenRepository = $paymentTokenRepository;
        $this->logger                 = $logger;
    }

    public function afterCreateVaultToken(
        AdyenVault $subject,
        PaymentTokenInterface $result,
        OrderPaymentInterface $payment,
        string $detailReference,
        ?string $cardHolderName = null
    ): PaymentTokenInterface {
        $additionalData = $payment->getAdditionalInformation('additionalData');
        if (!is_array($additionalData) || empty($additionalData['cardBin'])) {
            return $result;
        }

        $bin = (string) $additionalData['cardBin'];
        $details = json_decode((string) $result->getTokenDetails(), true) ?: [];

        if (($details['bin'] ?? null) === $bin) {
            return $result;
        }

        $details['bin'] = $bin;
        $result->setTokenDetails(json_encode($details, JSON_FORCE_OBJECT));

        // If the token was already persisted inside createVaultToken (update
        // branch), our mutation needs its own save. For a brand-new token,
        // getEntityId() is null and the caller's save picks up the change.
        if ($result->getEntityId()) {
            try {
                $this->paymentTokenRepository->save($result);
            } catch (\Exception $e) {
                $this->logger->log('Failed to persist BIN backfill for vault token: ' . $e->getMessage());
            }
        }

        return $result;
    }
}
