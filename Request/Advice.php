<?php
namespace Riskified\AdaptiveCheckout\Model\Request;

use Riskified\Common\Signature\HttpDataSignature;
use Riskified\OrderWebhook\Transport\CurlTransport;

/**
 * Class Advice
 * @package Riskified\Decider\Api\Request
 */
class Advice extends CurlTransport
{
    public function __construct()
    {
        parent::__construct(new HttpDataSignature(), null);
    }
}