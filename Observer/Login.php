<?php
namespace Travray\Watcher\Observer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use \Psr\Log\LoggerInterface;
use GeoIp2\WebService\Client;
use Magento\Framework\Logger\Monolog;
use Monolog\Logger;
use Monolog\Handler\SwiftMailerHandler;
use Monolog\Formatter\HtmlFormatter;
use Swift_SmtpTransport;
use Swift_Mailer;
use Swift_Message;

class Login implements ObserverInterface
{
    protected $logger;
    protected $geoClient;
    protected $scopeConfig;

    protected $watch;
    protected $subject;
    protected $toEmail;
    protected $fromEmail;
    protected $fromName;
    protected $maxmindUserId;
    protected $maxmindLicenseKey;
    public function __construct(
        LoggerInterface $logger,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepositoryInterface,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    )
    {
        $this->logger = $logger;
        $this->customerRepositoryInterface = $customerRepositoryInterface;
        $this->scopeConfig = $scopeConfig;
        $this->setConfigValues();

    }

    private function setConfigValues() {
        $this->watch = $this->scopeConfig->getValue('travray_watcher/logins/watch_logins', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $this->subject = $this->scopeConfig->getValue('travray_watcher/logins/login_from_different_location_subject', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $this->toEmail = $this->scopeConfig->getValue('travray_watcher/general/email_to_alert', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $this->fromEmail = $this->scopeConfig->getValue('travray_watcher/general/email_from', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $this->fromName = $this->scopeConfig->getValue('travray_watcher/general/email_from_name', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $this->maxmindUserId = $this->scopeConfig->getValue('travray_watcher/logins/maxmind_userid', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $this->maxmindLicenseKey = $this->scopeConfig->getValue('travray_watcher/logins/maxmind_license_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);

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

        //get customer info
        $customer = $observer->getModel();
        $customer = $this->customerRepositoryInterface->getById($customer->getId());
        $customerAttributeData = $customer->__toArray();

        // get details on last login
        $sLastLoginLocation = 'unknown';
        $sLastLoginIp = 'unknown';
        if (isset($customerAttributeData['custom_attributes']['last_login_location'])) {
            $sLastLoginLocation = $customerAttributeData['custom_attributes']['last_login_location']['value'];
        }
        if (isset($customerAttributeData['custom_attributes']['last_login_ip'])) {
            $sLastLoginIp = $customerAttributeData['custom_attributes']['last_login_ip']['value'];
        }

        // geolocate using MaxMind web service
        $client = new Client($this->maxmindUserId, $this->maxmindLicenseKey);
        $sIpAddress = $this->getUserIpAddr();
        $record = $client->city($sIpAddress);
        $sCurrentLoginLocation = '#'.$record->city->name . ', ' . $record->mostSpecificSubdivision->name . ', ' . $record->country->name;
        $sCustomerLabel = $customer->getFirstName() . ' ' . $customer->getLastName() . '[' . $customer->getId() . ']';
        $this->logger->critical($sCustomerLabel . ' LAST LOGIN IP: ' . $sLastLoginIp . ', THIS LOGIN IP: ' . $sIpAddress);
        if ($sLastLoginLocation != $sCurrentLoginLocation) {
            $this->initializeEmailLogger();
        }
        $this->logger->critical($sCustomerLabel . ' LAST LOGIN LOCATION: ' . $sLastLoginLocation . ', THIS LOGIN LOCATION: ' . $sCurrentLoginLocation);

        $customer->SetCustomAttribute('last_login_location', $sCurrentLoginLocation);
        $customer->SetCustomAttribute('last_login_ip', $sIpAddress);
        $this->customerRepositoryInterface->save($customer);
    }
    public function getUserIpAddr() {
        if(!empty($_SERVER['HTTP_CLIENT_IP'])){
            //ip from share internet
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        }elseif(!empty($_SERVER['HTTP_X_FORWARDED_FOR'])){
            //ip pass from proxy
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }else{
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return $ip;
    }
}