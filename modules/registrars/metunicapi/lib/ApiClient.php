<?php

namespace WHMCS\Module\Registrar\Metunicapi;

class ApiClient
{
    const API_URL_DEFAULT = 'https://api-test.metunic.com.tr/v1/';
    protected $baseUrl = self::API_URL_DEFAULT;

    protected $results = array();
    protected $cookieJar;
    protected $lastEnvelope = array();

    public function __construct()
    {
        $this->cookieJar = __DIR__ . DIRECTORY_SEPARATOR . 'metunic_cookie.txt';
    }

    public function ensureAuthenticated($username, $password)
    {
        $check = $this->request('GET', 'session/check');
        if (is_array($check) && isset($check['valid']) && $check['valid'] === true) {
            return;
        }
        $this->request('POST', 'login/auth', array(
            'username' => $username,
            'password' => $password,
        ));
    }

    public function call($method, $path, $params = array(), $body = null, $auth = array())
    {
        if (!empty($auth)) {
            $this->ensureAuthenticated($auth['username'], $auth['password']);
        }
        $response = $this->http($method, $path, $params, $body);
        $decoded = $this->processResponse($response);
        $this->lastEnvelope = $decoded;
        logModuleCall(
            'metunicapi',
            $method . ' ' . $path,
            array('params' => $params, 'body' => $body),
            $response,
            $decoded,
            array(
                isset($auth['username']) ? $auth['username'] : '',
                isset($auth['password']) ? $auth['password'] : '',
            )
        );
        if ($decoded === null || json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Bad response received from API');
        }
        if (isset($decoded['messageCode']) && (int)$decoded['messageCode'] !== 1) {
            $msg = isset($decoded['messageText']) ? $decoded['messageText'] : 'API error';
            throw new \Exception($msg);
        }
        $this->results = isset($decoded['result']) ? $decoded['result'] : $decoded;
        return $this->results;
    }

    protected function http($method, $path, $params = array(), $body = null)
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        if (!empty($params) && strtoupper($method) === 'GET') {
            $url .= '?' . http_build_query($params);
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 100);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieJar);
        $method = strtoupper($method);
        if ($method === 'POST' || $method === 'PUT' || $method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            } elseif (!empty($params) && $method !== 'GET') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            }
        }
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new \Exception('Connection Error: ' . curl_errno($ch) . ' - ' . curl_error($ch));
        }
        curl_close($ch);
        return $response;
    }

    public function processResponse($response)
    {
        return json_decode($response, true);
    }

    public function getFromResponse($key)
    {
        return isset($this->results[$key]) ? $this->results[$key] : '';
    }

    public function getLastEnvelope()
    {
        return $this->lastEnvelope;
    }

    public function queryServiceIdByDomain($domain, $auth)
    {
        $res = $this->call('GET', 'services/queried-services', array('domainName' => $domain), null, $auth);
        if (is_array($res) && isset($res['serviceId'])) {
            return $res['serviceId'];
        }
        if (is_array($res) && isset($res['id'])) {
            return $res['id'];
        }
        throw new \Exception('Service not found for domain');
    }

    public function formatPhoneE164($phone)
    {
        $p = preg_replace('/[^\d+]/', '', $phone);
        if ($p && $p[0] !== '+') {
            $p = '+' . $p;
        }
        return $p;
    }

    public function setBaseUrl($url)
    {
        $this->baseUrl = $url;
    }
}
