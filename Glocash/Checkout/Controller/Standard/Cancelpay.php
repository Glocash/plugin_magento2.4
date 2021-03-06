<?php

namespace Glocash\Checkout\Controller\Standard;

class Cancelpay extends \Glocash\Checkout\Controller\Pay
{

    public function execute()
    {
        $this->_cancelPayment();
        $this->_checkoutSession->restoreQuote();
        $this->getResponse()->setRedirect(
            $this->getCheckoutHelper()->getUrl('checkout')
        );
    }

}
