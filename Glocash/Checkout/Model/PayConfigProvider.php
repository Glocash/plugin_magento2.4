<?php

namespace Glocash\Checkout\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Payment\Helper\Data as PaymentHelper;

class PayConfigProvider implements ConfigProviderInterface
{
    protected $methodCode = "glocash_pay";

    protected $method;

    public function __construct(
        PaymentHelper $paymentHelper
    ) {
        $this->method = $paymentHelper->getMethodInstance($this->methodCode);
    }

    public function getConfig()
    {
        return $this->method->isAvailable() ? [
            'payment' => [
                'glocash_pay' => [
                    'redirectUrl' => $this->method->getRedirectUrl()
                ]
            ]
        ] : [];
    }

    protected function getRedirectUrl()
    {
        return $this->method->getRedirectUrl();
    }
}
