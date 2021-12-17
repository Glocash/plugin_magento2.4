<?php

namespace Glocash\Checkout\Model;

use Magento\Quote\Model\Quote\Payment;
use Glocash\Checkout\Helper\Logs;

class Pay extends \Magento\Payment\Model\Method\AbstractMethod
{
    const CODE = 'glocash_pay';
    protected $_code = self::CODE;
    protected $helper;
    protected $_minAmount = null;
    protected $_maxAmount = null;

    protected $httpClientFactory;
    protected $orderSender;
	
	protected $urlBuilder;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Glocash\Checkout\Helper\Pay $helper,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Framework\HTTP\ZendClientFactory $httpClientFactory,
		\Magento\Framework\UrlInterface $urlBuilder
    ) {
        $this->helper = $helper;
        $this->orderSender = $orderSender;
        $this->httpClientFactory = $httpClientFactory;
		$this->urlBuilder = $urlBuilder;

        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger
        );

        //$this->_minAmount = $this->getConfigData('min_order_total');
        //$this->_maxAmount = $this->getConfigData('max_order_total');
    }

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if ($quote && (
                $quote->getBaseGrandTotal() < $this->_minAmount
                || ($this->_maxAmount && $quote->getBaseGrandTotal() > $this->_maxAmount))
        ) {
            return false;
        }

        return parent::isAvailable($quote);
    }

    public function buildCheckoutRequest($quote)
    {
        $billing_address = $quote->getBillingAddress();
        
        $params = array();

        $params["sid"]                  = $this->getConfigData("merchant_id");
        $params["merchant_order_id"]    = $quote->getReservedOrderId();
        $params["cart_order_id"]        = $quote->getReservedOrderId();
        $params["currency_code"]        = $quote->getQuoteCurrencyCode();
        $params["total"]                = round($quote->getGrandTotal(), 2);
        $params["card_holder_name"]     = $billing_address->getName();
        $params["street_address"]       = $billing_address->getStreet()[0];
        if (count($billing_address->getStreet()) > 1) {
            $params["street_address2"]  = $billing_address->getStreet()[1];
        }
        $params["city"]                 = $billing_address->getCity();
        $params["state"]                = $billing_address->getRegion();
        $params["zip"]                  = $billing_address->getPostcode();
        $params["country"]              = $billing_address->getCountryId();
        $params["email"]                = $quote->getCustomerEmail();
        $params["phone"]                = $billing_address->getTelephone();
        //$params["return_url"]           = $this->getCancelUrl();
        //$params["x_receipt_link_url"]   = $this->getReturnUrl();
        $params["purchase_step"]        = "payment-method";
        $params["pay_direct"]        = "Y";

        return $params;
    }

	public function getGlocashUrl($quote,$orderId){
		Logs::logw("#".$quote->getReservedOrderId()." order_id:".$orderId,"glocash.log","getGlocashUrl");
		$param= array(
				//付款请求参数
				'REQ_TIMES'=>time(),
				'REQ_EMAIL'=>$this->getConfigData("api_user"),
				'REQ_INVOICE'=>$quote->getReservedOrderId(),
                'REQ_APPID'=>$this->getConfigData("req_appid"),
				'CUS_EMAIL'=>$quote->getCustomerEmail(),
				'BIL_PRICE'=>round($quote->getGrandTotal(), 2),
				'BIL_CURRENCY'=>$quote->getQuoteCurrencyCode(),
				'BIL_METHOD'=>$this->getConfigData("method"),
				'BIL_CC3DS'=>$this->getConfigData("secure3d"),
				//付款处理后，付款人的终端浏览器将跳转至对应的地址
				'URL_SUCCESS'=>$this->helper->getUrl('glocash/standard/responsepay')."?invoice=".$quote->getReservedOrderId().'&t=s&CUS_EMAIL='.$quote->getCustomerEmail(),
				'URL_PENDING'=>$this->helper->getUrl('glocash/standard/responsepay')."?invoice=".$quote->getReservedOrderId().'&t=p&CUS_EMAIL='.$quote->getCustomerEmail(),
				'URL_FAILED'=>$this->helper->getUrl('glocash/standard/fail')."?invoice=".$quote->getReservedOrderId().'&t=f',
				//'URL_FAILED'=>$this->urlBuilder->getUrl('glocash/standard/responsepay'),
				'URL_NOTIFY'=>$this->helper->getUrl('glocash/standard/responsenotify'),
				'BIL_GOODSNAME'=>$this->getGoodsName($quote),// 展示在付款页面的商品描述
		);
		$param['REQ_SIGN'] = hash("sha256",
				$this->getConfigData("merchant_id").
				$param['REQ_TIMES'].
				$param['REQ_EMAIL'].
				$param['REQ_INVOICE'].
				$param['CUS_EMAIL'].
				$param['BIL_METHOD'].
				$param['BIL_PRICE'].
				$param['BIL_CURRENCY']
		);
		
		$sandbox=$this->getConfigData("sandbox");
		$terminal=$this->getConfigData("terminal");
		if($sandbox)
			$gatewayUrl='https://sandbox.'.$terminal.'.com/gateway/payment/index';
		else
			$gatewayUrl='https://pay.'.$terminal.'.com/gateway/payment/index';
				
		$httpCode = $this->paycurl($gatewayUrl, http_build_query($param), $result);
		$datas = json_decode($result, true);
		$action='';
		if ($httpCode!=200 || empty($datas['URL_PAYMENT'])) {
			// 请求失败
			Logs::logw("#".$quote->getReservedOrderId()." Request connection failed \n url:".$gatewayUrl." method:post Request:".json_encode($param)." Result:".$result,"glocash.log","payment_url");
			
			$action=$this->helper->getUrl('glocash/standard/fail')."?invoice=".$quote->getReservedOrderId().'&t=f&error='.$datas['REQ_ERROR'];
			
		}
		else{
			$action=$datas['URL_PAYMENT'];
			//$action=str_replace("https","http",$action);   //测试
			Logs::logw("#".$quote->getReservedOrderId()." url:".$gatewayUrl." method:post Request:".json_encode($param)." Result:".$result,"glocash.log","payment_url");
		}
		
		$arr=array(
			"url"=>$action,
			"message"=>$result,
		);

		
		return json_encode($arr);
	}
	
	private function paycurl( $url, $postData, &$result ){
		$options = array();
		if (!empty($postData)) {
			$options[CURLOPT_CUSTOMREQUEST] = 'POST';
			$options[CURLOPT_POSTFIELDS] = $postData;
		}
		$options[CURLOPT_USERAGENT] = 'Glocash/v2.*/CURL';
		$options[CURLOPT_ENCODING] = 'gzip,deflate';
		$options[CURLOPT_HTTPHEADER] = [
		'Accept: text/html,application/xhtml+xml,application/xml',
		'Accept-Language: en-US,en',
		'Pragma: no-cache',
		'Cache-Control: no-cache'
				];
		$options[CURLOPT_RETURNTRANSFER] = 1;
		$options[CURLOPT_HEADER] = 0;
		if (substr($url,0,5)=='https') {
			$options[CURLOPT_SSL_VERIFYPEER] = false;
		}
		$ch = curl_init($url);
		curl_setopt_array($ch, $options);
		$result = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		return $httpCode;
	}

    public function validateResponse($quote)
    {
        $merchantId = $this->getConfigData('merchant_id');

		$sign = hash("sha256",
				$merchantId.
				$quote['REQ_TIMES'].
				$this->getConfigData("api_user").
				$quote['CUS_EMAIL'].
				$quote['TNS_GCID'].
				$quote['BIL_STATUS'].
				$quote['BIL_METHOD'].
				$quote['PGW_PRICE'].
				$quote['PGW_CURRENCY']
		);
		
		if($sign==$quote['REQ_SIGN']){
			return true;
		}
		else{
			return false;
		}
		
    }

    public function postProcessing(\Magento\Sales\Model\Order $order, \Magento\Framework\DataObject $payment, $response) {
        // Update payment details
        /*$payment->setTransactionId($response['REQ_INVOICE']);
        $payment->setIsTransactionClosed(0);
        $payment->setTransactionAdditionalInfo('glocash_order_number', $response['REQ_INVOICE']);
        $payment->setAdditionalInformation('glocash_order_number', $response['REQ_INVOICE']);
        $payment->setAdditionalInformation('glocash_order_status', 'approved');
        $payment->place();*/


        // Update order status
        $order->setStatus($this->getOrderStatus());
        $order->setExtOrderId($response['REQ_INVOICE']);
        $order->save();

		Logs::logw("#".$response['REQ_INVOICE']." Successful modification of order status,ID:".$order->getId()." Number:".$response['REQ_INVOICE']." Status:".$this->getOrderStatus(),"glocash.log","Order_modification");
		
        // Send email confirmation
        //$this->orderSender->send($order);
    }
	
	public function postClosed(\Magento\Sales\Model\Order $order, \Magento\Framework\DataObject $payment, $response) {
		// Update order status
        $order->setStatus("canceled");
        $order->setExtOrderId($response['REQ_INVOICE']);
        $order->save();

		Logs::logw("#".$response['REQ_INVOICE']." Successful modification of order status,ID:".$order->getId()." Number:".$response['REQ_INVOICE']." Status:".$this->getOrderStatus(),"glocash.log","Order_modification");
		
	}
    
    public function getRedirectUrl()
    {
        $url = $this->helper->getUrl($this->getConfigData('redirect_url'));
        return $url;
    }

    public function getOrderStatus()
    {
        $value = $this->getConfigData('order_status');
        return $value;
    }

    public function getGoodsName($quote) {
        $goodsName = [];
        foreach($quote->getAllItems() as $item){
            $goodsName[] = $item->getName() . ' x ' . $item->getQty();
        }
        return implode(';', $goodsName);
    }

}