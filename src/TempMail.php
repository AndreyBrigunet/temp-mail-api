<?php
namespace TempMailAPI;

use TempMailAPI\Exceptions;
use Sunra\PhpSimple\HtmlDomParser;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

class TempMail {

    protected $domains = null;
    protected $cookieJar = null;
    protected $cookieJarDomain = ".";
    protected $mainUrl = "https://temp-mail.org/en/";
    protected $refreshUrl = "https://temp-mail.org/en/option/refresh/";
    protected $domainsUrl = "http://temp-mail.org/en/option/change/";
    protected $proxyUrl = null;

    /**
     * Sets proxy url.
     * Format:
     * http://domain.com/proxy.php?url=
     * 
     * @param string $url
     * @return void
     */
    public function setProxy($url) {
        $this->proxyUrl = trim($url);
    }

    /**
     * Return api url and prepends proxy url .
     * 
     * @return string
     */
    protected function prepareUrl($url) {
        return ($this->proxyUrl ?: null) . $url;
    }

    /**
     * Returns the available domains list on temp-mail.org
     * 
     * @return Array
     */
    public function getDomains() {
        $client = new Client();
        $response = $client->get($this->prepareUrl($this->domainsUrl));
        $dom = HtmlDomParser::str_get_html($response->getBody());

        $domainSelectBoxOptions = $dom->find('#domain > option');
        foreach($domainSelectBoxOptions as $optionEl) {
            $this->domains[] = trim($optionEl->value);
        }
        return $this->domains;
    }

    /**
     * Creates new mail address
     * If $mail and $domain parameters not defined, generates a random mail with random domain.
     * If only $mail parameter defined, generates a new mail address with random domain.
     * 
     * $domain must be start with @(at) character and must be available on temp-mail.org.
     * Otherwise throws InvalidDomainException
     * 
     * @param string $mail
     * @param string $domain
     * @return string
     */
    public function getNewAddress($mail = null, $domain = null) {
        try {
            $domains = $this->getDomains();

            if ($domain) {
                if (!in_array($domain, $domains)) {
                    throw new Exceptions\UndefinedDomainException();
                }
            } else {
                $domain = $domains[array_rand($domains)];
            }

            if (!strstr($domain, "@")) {
                throw new Exceptions\InvalidDomainException();
            }
        } catch (\Exception $e) {
            echo $e->getMessage();
            return false;
        }

        $clientParameters = [];
        if ($mail && $domain) {
            $clientParameters["cookies"] = CookieJar::fromArray([
                "mail" => urlencode($mail . $domain)
            ], $this->cookieJarDomain);
        }

        $client = new Client($clientParameters);       
        $response = $client->get($this->prepareUrl($this->refreshUrl));

        $dom = HtmlDomParser::str_get_html($response->getBody());
        $mail = $dom->find("#mail", 0);

        return trim($mail->value);
    }

    /**
     * Returns all mails as an array with given mail address
     * 
     * @param string $mailAddress
     * @param boolean $unread
     * @return array
     */
    public function getMails($mailAddress = null, $filter = null) {
        try {
            if (!$mailAddress) {
                throw new Exceptions\FullMailAddressException();
            }

        } catch(\Exception $e) {
            echo $e->getMessage();
            return false;
        }

        $jar = CookieJar::fromArray([
            'mail' => urlencode($mailAddress)
        ], $this->cookieJarDomain);

        $client = new Client(['cookies' => $jar]);
        $response = $client->get($this->prepareUrl($this->refreshUrl));
        $dom = HtmlDomParser::str_get_html($response->getBody());

        $mailRows = $dom->find("#mails tbody tr");
        unset($mailRows[0]);

        $mails = [];
        foreach($mailRows as $row) {
            $senderItem = $row->find("a", 0);
            $subjectItem = $row->find("a", 1);

            // filter mails
            if ($filter) {
                if (!stristr($senderItem->plaintext, $filter) || !stristr($subjectItem->plaintext, $filter)) {
                    continue;
                }
            }

            $mails[] = [
                "sender" => trim($senderItem->plaintext),
                "subject" => trim($subjectItem->plaintext),
                "readUrl" => trim($senderItem->href)
            ];
        }
        return $mails;
    }

    /**
     * Returns mail body as html
     * 
     * @param string $readMailUrl
     * @return string
     */
    public function readMail($readMailUrl = null) {
        try {
            if (!$readMailUrl) {
                throw new Exceptions\InvalidMailUrlException();
            }

            $client = new Client();
            $response = $client->get($this->prepareUrl($readMailUrl));
            $dom = HtmlDomParser::str_get_html($response->getBody());

            $body = $dom->find(".pm-text", 0)->innertext;
            
            if ($body == "Message not found!") {
                throw new Exceptions\MessageNotFoundException();
            }

            return $body;
        } catch(\Exception $e) {
            echo $e->getMessage();
            return false;
        }
    }
}