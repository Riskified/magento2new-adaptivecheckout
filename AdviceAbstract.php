<?php
namespace Riskified\AdaptiveCheckout\Controller;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Registry;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Sales\Model\OrderFactory;
use Riskified\AdaptiveCheckout\Model\AdviceEvaluator;
use Riskified\AdaptiveCheckout\Model\Builder\Advice as AdviceBuilder;
use Riskified\AdaptiveCheckout\Model\Config as AdviceConfig;
use Riskified\AdaptiveCheckout\Model\Request\Advice as AdviceRequest;
use Riskified\AdaptiveCheckout\Model\Security\CartStateHasher;
use Riskified\Decider\Model\Api\Api;
use Riskified\Decider\Model\Api\Log as Logger;
use Riskified\Decider\Model\Api\Order as OrderApi;

abstract class AdviceAbstract extends Action implements CsrfAwareActionInterface
{
    /** @var Registry */
    protected $registry;
    /** @var AdviceBuilder */
    protected $adviceBuilder;
    /** @var AdviceRequest */
    protected $adviceRequest;
    /** @var Api */
    protected $api;
    /** @var Logger */
    protected $logger;
    /** @var \Magento\Checkout\Model\Session */
    protected $session;
    /** @var \Magento\Framework\App\RequestInterface */
    protected $request;
    /** @var OrderApi */
    protected $apiOrderLayer;
    /** @var CartRepositoryInterface */
    protected $cartRespository;
    /** @var OrderFactory */
    protected $orderFactory;
    /** @var QuoteIdMaskFactory */
    protected $quoteIdMaskFactory;
    /** @var JsonFactory */
    protected $resultJsonFactory;
    /** @var CartStateHasher */
    protected $hasher;
    /** @var FormKeyValidator */
    protected $formKeyValidator;
    /** @var AdviceEvaluator */
    protected $adviceEvaluator;
    /** @var AdviceConfig */
    protected $adviceConfig;

    public function __construct(
        JsonFactory $resultJsonFactory,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        Context $context,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Checkout\Model\Session $session,
        CartRepositoryInterface $cartRespository,
        OrderFactory $orderFactory,
        AdviceBuilder $adviceBuilder,
        AdviceRequest $adviceRequest,
        OrderApi $orderApi,
        Registry $registry,
        Logger $logger,
        Api $api,
        CartStateHasher $hasher,
        FormKeyValidator $formKeyValidator,
        AdviceEvaluator $adviceEvaluator,
        AdviceConfig $adviceConfig
    ) {
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->resultJsonFactory  = $resultJsonFactory;
        $this->adviceBuilder      = $adviceBuilder;
        $this->adviceRequest      = $adviceRequest;
        $this->cartRespository    = $cartRespository;
        $this->orderFactory       = $orderFactory;
        $this->apiOrderLayer      = $orderApi;
        $this->registry           = $registry;
        $this->request            = $request;
        $this->session            = $session;
        $this->logger             = $logger;
        $this->api                = $api;
        $this->hasher             = $hasher;
        $this->formKeyValidator   = $formKeyValidator;
        $this->adviceEvaluator    = $adviceEvaluator;
        $this->adviceConfig       = $adviceConfig;

        parent::__construct($context);
    }

    /**
     * Sends Denied Quote to Riskified Api.
     */
    protected function sendDeniedOrderToRiskified($quote)
    {
        $orderFactory = $this->orderFactory->create();
        $order = $orderFactory->loadByAttribute('quote_id', $quote->getEntityId());

        if (is_numeric($order->getEntityId()) != 1) {
            $order = $quote;
        }
        $this->apiOrderLayer->post(
            $order,
            Api::ACTION_CHECKOUT_DENIED
        );

        return $this;
    }

    /**
     * Returns unmasked quote id. Rejects numeric cart IDs to prevent
     * enumeration of raw primary keys, and binds the resolved quote to the
     * caller's checkout session — without that check, anyone with a leaked
     * masked_id (referer, browser history, kiosk, XSS) could hit advice/deny
     * against another user's cart (IDOR).
     *
     * @throws \Exception
     */
    protected function getQuoteId($cartId): int
    {
        if (empty($cartId)) {
            throw new \Exception('Cart ID cannot be empty');
        }

        if (is_numeric($cartId)) {
            $this->logger->log('Rejected numeric cart ID (enumeration attempt): ' . $cartId);
            throw new \Exception('Invalid cart ID');
        }

        $quoteIdMask = $this->quoteIdMaskFactory->create()->load($cartId, 'masked_id');
        $resolvedQuoteId = (int) $quoteIdMask->getQuoteId();

        if ($resolvedQuoteId === 0) {
            throw new \Exception('Invalid masked cart ID');
        }

        $sessionQuoteId = (int) $this->session->getQuoteId();
        if ($sessionQuoteId === 0 || $sessionQuoteId !== $resolvedQuoteId) {
            $this->logger->log(sprintf(
                'Rejected cross-session cart access: session quote=%d, requested quote=%d',
                $sessionQuoteId,
                $resolvedQuoteId
            ));
            throw new \Exception('Invalid cart ID');
        }

        return $resolvedQuoteId;
    }

    /**
     * Checks if Advice Call is enabled in admin panel, resolved against the
     * cart's own store rather than the current request's. The storefront store
     * can be switched by the caller (___store), so scoping this to the request
     * would let the same cart be evaluated under a different store's config
     * than the one the place-order gates use. See Riskified\AdaptiveCheckout\Model\Config.
     */
    protected function isEnabled(CartInterface $quote): bool
    {
        return $this->adviceConfig->isAdviceEnabledForQuote($quote);
    }

    /**
     * CSRF: require a valid form_key. The advice/deny endpoints accept
     * state-changing POSTs that previously had no CSRF protection — any
     * third-party site could trigger them using the victim's session cookies.
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $this->formKeyValidator->validate($request);
    }
}
