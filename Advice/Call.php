<?php

namespace Riskified\AdaptiveCheckout\Controller\Advice;

use Riskified\AdaptiveCheckout\Model\AdviceResult;

class Call extends \Riskified\AdaptiveCheckout\Controller\AdviceAbstract
{
    private const array ALLOWED_GATEWAYS = ['adyen', 'adyen_cc', 'braintree', 'worldpay', 'iyzico', 'unavailable'];

    /**
     * Validates the request, then delegates the advice call, response
     * interpretation and quote persistence to AdviceEvaluator (shared with the
     * backend place-order flow). Returns the advice status/message to the
     * frontend.
     *
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\Result\Json|\Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $payload = $this->request->getContent();

        parse_str($payload, $params);

        // Validate required parameters
        if (!isset($params['quote_id']) || !$params['quote_id']) {
            $this->logger->log('Advice API call failed: Missing quote_id parameter');
            return $this->resultJsonFactory->create()->setData(['status' => 1, 'message' => 'Missing quote ID']);
        }

        try {
            $quoteId = $this->getQuoteId($params['quote_id']);
            $quote = $this->cartRespository->get($quoteId);
        } catch (\Exception $e) {
            $this->logger->log('Advice API call failed: Invalid quote_id parameter: ' . $params['quote_id'] . '. Error: ' . $e->getMessage());
            return $this->resultJsonFactory->create()->setData(['status' => 1, 'message' => 'Invalid quote ID']);
        }

        if (!$quote || !$quote->getId()) {
            $this->logger->log('Advice API call failed: Quote does not exist for ID: ' . $params['quote_id']);
            return $this->resultJsonFactory->create()->setData(['status' => AdviceResult::STATUS_ERROR, 'message' => 'Quote does not exist']);
        }

        // Checked only once the cart is known — `enabled` is store-scoped and
        // must be resolved against the cart's store, not the request's.
        if ($this->isEnabled($quote) === false) {
            return $this->resultJsonFactory->create()->setData(['status' => 1]);
        }

        $quoteEmail   = trim((string) $quote->getCustomerEmail());
        $payloadEmail = isset($params['email']) ? trim((string) $params['email']) : '';

        $adviceEmail = $quoteEmail !== '' ? $quoteEmail : $payloadEmail;
        if ($adviceEmail === '' || !filter_var($adviceEmail, FILTER_VALIDATE_EMAIL)) {
            $this->logger->log('Advice API call failed: no valid email available for quote ID: ' . $quote->getId());
            return $this->resultJsonFactory->create()->setData(['status' => 1, 'message' => 'Please enter your email before checkout']);
        }

        // Persist the resolved email onto the quote so the evaluator (which
        // reads it server-side) picks it up.
        $quote->setCustomerEmail($adviceEmail);

        // Required, not merely validated-when-present: riskified_advice_gateway
        // binds the advice decision to a gateway at order placement, so a
        // missing/blank value here would let the bound gateway be silently
        // empty — defeating that anti-tamper check entirely.
        if (empty($params['gateway']) || !in_array($params['gateway'], self::ALLOWED_GATEWAYS, true)) {
            $this->logger->log('Advice API call: Invalid gateway parameter: ' . ($params['gateway'] ?? '(missing)'));

            return $this->resultJsonFactory->create()->setData(['status' => AdviceResult::STATUS_ERROR, 'message' => 'Not valid payment gateway.']);
        }

        $result = $this->adviceEvaluator->evaluate($quote, (string) $params['gateway'], $params);

        return $this->resultJsonFactory->create()->setData([
            'status'  => $result->status,
            'message' => $result->message,
        ]);
    }
}