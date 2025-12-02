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
        // Use http directly to avoid infinite loop if call uses ensureAuthenticated
        $checkResponse = $this->http('GET', 'session/check');
        $check = $this->processResponse($checkResponse);

        if (is_array($check) && isset($check['valid']) && $check['valid'] === true) {
            return;
        }

        // If not valid, login.
        $this->http('POST', 'login/auth', array(
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
                // If body is JSON, set header
                if (is_string($body) && (strpos($body, '{') === 0 || strpos($body, '[') === 0)) {
                     curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
                }
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

    public function getTrCountryId($code, $auth)
    {
        // $code is ISO-2 (e.g., TR)
        // Need to fetch list and find ID.
        // Caching could be done here, but for now simple lookup.
        $res = $this->call('GET', 'lookup/tr/countries', array(), null, $auth);

        // Assuming response structure: list of {id: 215, name: "Türkiye", code: "TR"} or similar.
        // If not standard structure, we guess.
        $list = isset($res['countries']) ? $res['countries'] : (isset($res['data']) ? $res['data'] : $res);

        if (is_array($list)) {
            foreach ($list as $c) {
                // Check multiple fields
                $cCode = isset($c['code']) ? $c['code'] : (isset($c['alpha2']) ? $c['alpha2'] : '');
                if (strtoupper($cCode) === strtoupper($code)) {
                    return isset($c['id']) ? $c['id'] : null;
                }
            }
        }
        return null;
    }

    public function getTrCityId($countryId, $cityName, $auth)
    {
        // Need to fetch list for countryId.
        $res = $this->call('GET', 'lookup/tr/states/' . $countryId, array(), null, $auth);
        $list = isset($res['states']) ? $res['states'] : (isset($res['data']) ? $res['data'] : $res);

        if (is_array($list)) {
            foreach ($list as $s) {
                 $sName = isset($s['name']) ? $s['name'] : '';
                 // Simple loose comparison
                 if (mb_strtolower($sName, 'UTF-8') === mb_strtolower($cityName, 'UTF-8')) {
                     return isset($s['id']) ? $s['id'] : null;
                 }
            }
        }
        return null;
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
