<?php
namespace Travray\Watcher\Observer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use mysql_xdevapi\Exception;
use \Psr\Log\LoggerInterface;
use GeoIp2\WebService\Client;
use Magento\Framework\Logger\Monolog;
use Monolog\Logger;
use Monolog\Handler\SwiftMailerHandler;
use Monolog\Formatter\HtmlFormatter;
use Swift_SmtpTransport;
use Swift_Mailer;
use Swift_Message;

class Productview implements ObserverInterface
{
    protected $logger;
    protected $geoClient;
    protected $customerSession;
    protected $scopeConfig;

    protected $toEmail;
    protected $maxProductViews;
    protected $productViewsToAlert;
    protected $watch;
    protected $fromEmail;
    protected $fromName;
    protected $subject;

    public function __construct(
        LoggerInterface $logger,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepositoryInterface,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    )
    {
        $this->logger = $logger;
        $this->customerRepositoryInterface = $customerRepositoryInterface;
        $this->customerSession = $customerSession;
        $this->scopeConfig = $scopeConfig;
        $this->setConfigValues();
    }

    private function setConfigValues() {
        $this->watch = $this->scopeConfig->getValue('travray_watcher/product_views/watch_product_views', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $this->subject = $this->scopeConfig->getValue('travray_watcher/product_views/product_views_subject', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $this->toEmail = $this->scopeConfig->getValue('travray_watcher/general/email_to_alert', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $this->fromEmail = $this->scopeConfig->getValue('travray_watcher/general/email_from', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $this->fromName = $this->scopeConfig->getValue('travray_watcher/general/email_from_name', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $this->maxProductViews = $this->scopeConfig->getValue('travray_watcher/product_views/max_product_views', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $this->productViewsToAlert = $this->scopeConfig->getValue('travray_watcher/product_views/product_views_to_email', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);

    }

    private function initializeEmailLogger() {
        $transporter = new Swift_SmtpTransport('localhost', 25);
        $mailer = new Swift_Mailer($transporter);
        $message = (new Swift_Message($this->subject));
        $message->setFrom([$this->fromEmail => $this->fromName]);
        $message->setTo([$this->toEmail => $this->toEmail]);
        $message->setContentType("text/html");
        $logger = new Logger('default');
        $mailerHandler = new SwiftMailerHandler($mailer, $message);
        $mailerHandler->setFormatter(new HtmlFormatter());
        $this->logger->pushHandler($mailerHandler);
    }


    public function execute(Observer $observer)
    {
        // Is this feature enabled?
        if (!$this->watch) {
            return;
        }

        // Is this user logged in?
        $customer = $this->customerSession->getCustomer();
        if (!$customer->getId()) {
            return;
        }

        //get customer details
        $customer = $this->customerRepositoryInterface->getById($customer->getId());
        $sCustomerLabel = $customer->getFirstName() . ' ' . $customer->getLastName() . '[' . $customer->getId() . ']';
        $customerAttributeData = $customer->__toArray();


        // get today's date and date from customer attributes
        $sProductViewsDate = '';
        $sCurrentDate = date('Y:m:d');
        if (isset($customerAttributeData['custom_attributes']['product_views_date'])) {
            $sProductViewsDate = $customerAttributeData['custom_attributes']['product_views_date']['value'];
        }


        // get current count (1 if not previously set)
        $iCount = 1;
        if (isset($customerAttributeData['custom_attributes']['product_views_count'])) {
            $iCount = $customerAttributeData['custom_attributes']['product_views_count']['value'];
        }
//        $this->logger->notice($sCustomerLabel . ' has attributes: ' . json_encode($customerAttributeData['custom_attributes']));

        // set date and count on customer
        if ($sCurrentDate != $sProductViewsDate) {
//            $this->logger->notice($sCustomerLabel . ' has no matched date');
            // set views_count to 1, date to today
            $customer->SetCustomAttribute('product_views_date', $sCurrentDate);
            $customer->SetCustomAttribute('product_views_count', 1);
        } else {
            // increment product_views_count
            $iCount = $iCount + 1;
//            $this->logger->notice($sCustomerLabel . ' has matched date ' . $iCount);
            $customer->SetCustomAttribute('product_views_count', $iCount);
        }
        $this->customerRepositoryInterface->save($customer);

        // send an email if customer is passing the alert level, or a multiple of it
        if ($iCount % $this->productViewsToAlert === 0) {
//            $this->logger->notice('Watcher enabling email');
            $this->initializeEmailLogger();
        }
        $this->logger->critical($sCustomerLabel . ' has viewed: ' . $iCount . ' products on: ' . $sCurrentDate);

        // throw an error if the user has passed the maximum allowed product views
        if ($iCount > $this->maxProductViews) {
            throw new \Magento\Framework\Exception\NotFoundException(__('TOO MANY PRODUCT VIEWS'));
        }

    }

}