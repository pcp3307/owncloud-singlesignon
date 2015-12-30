<?php
namespace OCA\SingleSignOn;

use OCP\IPreFilter;
use Exception;

class SingleSignOnPreFilter implements IPreFilter {

    private $token;
    private $ssoconfig;
    private $userIp;
    private $redirectUrl;
    private $soapClient;
    private $hostIp;
    private $hostDomainName;

    public function run() {
        try {
            $this->process();
        }catch (Exception $e){
            echo $e->getMessage();
        }
    }

    public function __construct() {
        $this->ssoconfig = \OC::$server->getSystemConfig()->getValue("SSOCONFIG");
        $this->userIp = $_SERVER["REMOTE_ADDR"];
        $this->token = isset($_COOKIE[$this->ssoconfig["token"]]) ? $_COOKIE[$this->ssoconfig["token"]] : false;
        $this->redirectUrl = isset($_GET["redirect_url"]) ? $_GET["redirect_url"] : false;
        RequestManager::init("soap", $this->ssoconfig["singleSignOnServer"], $this->ssoconfig["requests"]);
    }

    public function process() {
        $ssoUrl = $this->ssoconfig["ssoUrl"];
        $redirectUrl = $this->redirectUrl;
        $userInfo = RequestManager::getRequest(ISingleSignOnRequest::INFO);

        if(isset($_GET["logout"]) && $_GET["logout"] == "true") {
            if($this->ssoconfig["logoutSSO"]) {
                RequestManager::send(ISingleSignOnRequest::INVALIDTOKEN);
            }
            \OC_User::logout();
            Util::redirect($ssoUrl);
        }

        if(empty($ssoUrl)) {
            header("HTTP/1.1 " . \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
            header("Status: " . \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
            header("WWW-Authenticate: ");
            header("Retry-After: 120");

            $template = new \OC_Template("singlesignon", "verificationFailure", "guest");
            $template->printPage();
            die();
        }

        if(\OC_User::isLoggedIn() && ($this->token === false || !RequestManager::send(ISingleSignOnRequest::VALIDTOKEN, array("token" => $this->getToken(), "userIp" => $this->getUserIp())))) {
            header("HTTP/1.1 " . \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
            header("Status: " . \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
            header("WWW-Authenticate: ");
            header("Retry-After: 120");

            $template = new \OC_Template("singlesignon", "tokenExpired", "guest");
            $template->printPage();
            die();
        }

        if($this->getToken() === false || !RequestManager::send(ISingleSignOnRequest::VALIDTOKEN, array("token" => $this->getToken(), "userIp" => $this->getUserIp()))) {
            $url = ($redirectUrl === false) ? $ssoUrl : $ssoUrl . $this->ssoconfig["returnUrl"] . $redirectUrl;
            Util::redirect($url);
        }

        if(\OC_User::isLoggedIn()) {
            return ;
        }

        if(!$userInfo->send(array("token" => $this->getToken(), "userIp" => $this->getUserIp()))) {
            return ;
        }

        if(!\OC_User::userExists($userInfo->getUserId())) {
            Util::firstLogin($userInfo);
            Util::redirect($redirectUrl);
        }
        else {
            Util::login($userInfo->getUserId());
            Util::redirect($redirectUrl);
        }
    }

    public static function getInstance() {
        return new static();
    }

    public function getToken() {
        return $this->token;
    }
    
    public function getUserIp() {
        return $this->userIp;
    }
}
