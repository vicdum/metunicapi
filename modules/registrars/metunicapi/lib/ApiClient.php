<?php

namespace WHMCS\Module\Registrar\Metunic;

/**
 * Metunic API Client for WHMCS
 * * Bu sınıf Metunic REST API işlemleri, oturum yönetimi (Cookie) ve 
 * HTTP isteklerini yönetir.
 */
class ApiClient
{
    private $apiUrl;
    private $username;
    private $password;
    private $cookieFile;
    private $debugMode = false;

    /**
     * @param string $username Metunic Kullanıcı Adı
     * @param string $password Metunic Şifresi
     * @param bool   $testMode Test modu aktif mi?
     */
    public function __construct($username, $password, $testMode = false)
    {
        $this->username = $username;
        $this->password = $password;

        // API URL Belirleme
        if ($testMode) {
            $this->apiUrl = 'https://api-test.metunic.com.tr/v1';
        } else {
            $this->apiUrl = 'https://api.metunic.com.tr/api';
        }

        // Cookie dosyası için güvenli ve benzersiz bir yol (Temp dizini)
        // Her kullanıcı/bayi için hash'lenmiş ayrı bir dosya tutuyoruz.
        $this->cookieFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'metunic_session_' . md5($username) . '.txt';
    }

    /**
     * API İsteği Gönderir
     * * @param string $endpoint API Endpoint (örn: /orders/tld)
     * @param string $method   HTTP Metodu (GET, POST, PUT, DELETE)
     * @param array  $params   Gönderilecek parametreler
     * @param bool   $authCheck Oturum kontrolü yapılsın mı? (Login döngüsünü önlemek için)
     * * @return array API Yanıtı
     * @throws \Exception
     */
    public function request($endpoint, $method = 'GET', $params = [], $authCheck = true)
    {
        // 1. Oturum Kontrolü (Gerekirse)
        if ($authCheck) {
            $this->ensureSession();
        }

        $ch = curl_init();
        
        // 2. URL ve Parametre Hazırlığı
        // Metunic API dokümanına göre POST/PUT isteklerinde bile parametreler 
        // "in: query" olarak belirtildiği için URL'e ekliyoruz.
        $url = $this->apiUrl . $endpoint;
        
        if (!empty($params)) {
            $queryString = http_build_query($params);
            $url .= '?' . $queryString;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        // 3. Cookie Yönetimi
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        
        // SSL Doğrulama (Prod ortamında açık olmalı, test ortamında bazen sorun çıkarabilir)
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        // Header Bilgileri
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/json' // Genellikle query string olsa da header json kalabilir.
        ]);

        // 4. İsteği Çalıştır
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        
        curl_close($ch);

        // 5. Hata Kontrolü (cURL)
        if ($curlErrno) {
            throw new \Exception('Metunic Connection Error: ' . $curlError);
        }

        // 6. Yanıtı Parse Et
        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // API bazen HTML hata dönebilir (Bakım modu vb.)
            throw new \Exception("Metunic API Bad Response (Status: $httpCode): Raw response could not be decoded.");
        }

        // 7. API Mantıksal Hata Kontrolü
        // messageCode: 1 -> Başarılı
        if (isset($result['messageCode']) && $result['messageCode'] != 1) {
            $errorText = isset($result['messageText']) ? $result['messageText'] : 'Unknown API Error';
            
            // Özel Durum: Oturum düşmüşse (Hata Kodu: 3 - Sisteme Giriş Yapılmalıdır)
            // Recursive olarak tekrar login olup isteği yapmayı deneyebiliriz.
            if ($result['messageCode'] == 3 && $authCheck) {
                // Cookie dosyasını sil ve tekrar dene
                if (file_exists($this->cookieFile)) {
                    unlink($this->cookieFile);
                }
                $this->login();
                return $this->request($endpoint, $method, $params, false); // Tekrar authCheck yapma
            }

            throw new \Exception($errorText);
        }

        return $result;
    }

    /**
     * Oturumun geçerli olup olmadığını kontrol eder, değilse login olur.
     */
    private function ensureSession()
    {
        // Cookie dosyası yoksa direkt login ol
        if (!file_exists($this->cookieFile)) {
            $this->login();
            return;
        }

        // Cookie dosyası var ama session geçerli mi? (/session/check)
        try {
            // authCheck = false gönderiyoruz ki sonsuz döngüye girmesin
            $check = $this->request('/session/check', 'GET', [], false);
            
            // API Dönüşü: {"result": {"valid": true}, ...}
            if (empty($check['result']['valid']) || $check['result']['valid'] !== true) {
                $this->login();
            }
        } catch (\Exception $e) {
            // Check başarısızsa (örn: token expire hatası aldıysa) yeniden login ol
            $this->login();
        }
    }

    /**
     * API'ye Login olur ve Cookie dosyasını oluşturur/günceller.
     */
    private function login()
    {
        // Login isteği gönder (authCheck = false)
        $this->request('/login/auth', 'POST', [
            'username' => $this->username,
            'password' => $this->password
        ], false);
        
        // cURL CURLOPT_COOKIEJAR sayesinde cookie dosyaya otomatik yazıldı.
        // Ekstra bir işlem yapmaya gerek yok.
    }

    /**
     * Domain isminden ServiceID bulur.
     * Metunic çoğu işlemde (NS güncelleme, Whois vb.) ServiceID kullanır.
     * * @param string $domainName (örn: example.com)
     * @return int ServiceID
     * @throws \Exception
     */
    public function getServiceIdByDomain($domainName)
    {
        // /services/queried-services endpoint'i domaine göre servis arar
        $response = $this->request('/services/queried-services', 'GET', ['domainName' => $domainName]);
        
        // Dönen yapı: { "result": { "id": 12345, "domainName": "...", ... } }
        if (isset($response['result']['id'])) {
            return $response['result']['id'];
        }

        throw new \Exception("Domain ID not found for: " . $domainName);
    }
}