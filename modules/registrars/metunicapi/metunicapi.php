<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/lib/ApiClient.php';

use WHMCS\Module\Registrar\Metunic\ApiClient;
use WHMCS\Database\Capsule;

/**
 * Modül Meta Verileri
 */
function metunic_MetaData()
{
    return [
        'DisplayName' => 'Metunic Registrar',
        'APIVersion' => '1.5',
    ];
}

/**
 * Modül Ayarları
 */
function metunic_getConfigArray()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Metunic',
        ],
        'username' => [
            'FriendlyName' => 'Kullanıcı Adı / E-posta',
            'Type' => 'text',
            'Size' => '50',
            'Description' => 'Metunic panel giriş bilgisi',
        ],
        'password' => [
            'FriendlyName' => 'Şifre',
            'Type' => 'password',
            'Size' => '50',
        ],
        'test_mode' => [
            'FriendlyName' => 'Test Modu',
            'Type' => 'yesno',
            'Description' => 'İşlemleri Sandbox ortamında yapar (api-test.metunic.com.tr)',
        ],
    ];
}

/**
 * ALAN ADI KAYDI (REGISTER)
 */
function metunic_RegisterDomain($params)
{
    $api = new ApiClient($params['username'], $params['password'], $params['test_mode']);

    $sld = $params['sld'];
    $tld = $params['tld'];
    $domainName = $sld . '.' . $tld;
    $years = $params['regperiod'];

    // Telefon numarası formatı (+905551234567 formatına zorlayalım)
    $phoneCC = preg_replace('/[^0-9]/', '', $params['phonecc']);
    $phoneNumber = preg_replace('/[^0-9]/', '', $params['phonenumber']);
    $fullPhone = '+' . $phoneCC . $phoneNumber;

    try {
        // --- SENARYO 1: .TR ALAN ADLARI (TRABIS) ---
        if (substr($tld, -3) == '.tr') {
            
            // Kayıt Türü Belirleme (Bireysel / Kurumsal)
            // WHMCS'de 'companyname' doluysa Kurumsal varsayıyoruz.
            $isOrg = !empty($params['companyname']);
            $registrantType = $isOrg ? 'organization' : 'individual';

            // TR Alan Adları için TCKN veya Vergi No
            // WHMCS'de "Tax ID" veya "Additional Domain Fields" içinden okumalıyız.
            $taxId = '';
            if (isset($params['additionalfields']['Tax ID'])) {
                $taxId = $params['additionalfields']['Tax ID'];
            } elseif (isset($params['additionalfields']['Identity Number'])) {
                $taxId = $params['additionalfields']['Identity Number'];
            } elseif (isset($params['tax_id'])) { // WHMCS Müşteri profili vergi no
                $taxId = $params['tax_id'];
            }

            $orderParams = [
                'domain' => $domainName,
                'duration' => $years,
                'registrant_type' => $registrantType,
                'registrant_name' => $params['firstname'] . ' ' . $params['lastname'],
                'registrant_email_address' => $params['email'],
                'registrant_phone' => $fullPhone,
                'registrant_address1' => $params['address1'],
                'registrant_address2' => $params['address2'] ?? '',
                // Metunic TR için İl Kodu (Plaka) isteyebilir, API hata verirse mapping gerekir.
                // Şimdilik string gönderiyoruz.
                'registrant_city' => $params['city'], 
                'registrant_country' => $params['countrycode'],
                'registrant_postal_code' => $params['postcode'],
                'ns1' => $params['ns1'],
                'ns2' => $params['ns2'],
            ];

            if ($isOrg) {
                $orderParams['registrant_organization'] = $params['companyname'];
                $orderParams['registrant_tax_office'] = 'Vergi Dairesi'; // Zorunluysa dummy veya custom field
                $orderParams['registrant_tax_number'] = $taxId;
            } else {
                $orderParams['registrant_citizen_id'] = $taxId; // TCKN
            }

            // Ek NS'ler
            if(!empty($params['ns3'])) $orderParams['ns3'] = $params['ns3'];
            if(!empty($params['ns4'])) $orderParams['ns4'] = $params['ns4'];

            $api->request('/orders/tr', 'POST', $orderParams);

        } else {
            // --- SENARYO 2: GTLD ALAN ADLARI (.COM, .NET vb.) ---

            // 1. Adım: Contact Oluşturma
            // TLD siparişlerinde Contact ID zorunludur.
            $contactParams = [
                'firstName' => $params['firstname'],
                'lastName' => $params['lastname'],
                'email' => $params['email'],
                'address1' => $params['address1'],
                'city' => $params['city'],
                'state' => $params['state'],
                'zip' => $params['postcode'],
                'country' => $params['countrycode'],
                'phoneNumber' => $fullPhone,
                'phoneNumberType' => 'mobile', // API default: phone
                'phoneNumberLocation' => 'work' // API default: home
            ];

            if (!empty($params['companyname'])) {
                $contactParams['company'] = $params['companyname'];
                $contactParams['title'] = 'Manager';
            }

            $contactResponse = $api->request('/contacts/add', 'POST', $contactParams);
            
            // Dönen ID'yi al (Yapı: response['result']['id'])
            $contactId = $contactResponse['result']['id'];

            if (!$contactId) {
                throw new \Exception('Contact creation failed, no ID returned.');
            }

            // 2. Adım: Sipariş Verme
            $orderParams = [
                'domainName' => $domainName,
                'duration' => $years,
                'owner' => $contactId,
                'billing' => $contactId,
                'technical' => $contactId,
                'admin' => $contactId,
                'ns1' => $params['ns1'],
                'ns2' => $params['ns2'],
            ];

            if(!empty($params['ns3'])) $orderParams['ns3'] = $params['ns3'];
            if(!empty($params['ns4'])) $orderParams['ns4'] = $params['ns4'];

            $api->request('/orders/tld', 'POST', $orderParams);
        }

        return ['success' => true];

    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * ALAN ADI YENİLEME (RENEW)
 */
function metunic_RenewDomain($params)
{
    try {
        $api = new ApiClient($params['username'], $params['password'], $params['test_mode']);
        
        $domainName = $params['sld'] . '.' . $params['tld'];
        $serviceId = $api->getServiceIdByDomain($domainName);

        $api->request("/services/{$serviceId}/renew-duration", 'POST', [
            'duration' => $params['regperiod']
        ]);

        return ['success' => true];

    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * ALAN ADI TRANSFER (TRANSFER)
 */
function metunic_TransferDomain($params)
{
    $api = new ApiClient($params['username'], $params['password'], $params['test_mode']);
    $domainName = $params['sld'] . '.' . $params['tld'];
    $eppCode = $params['eppcode'];

    try {
        // Transfer endpointleri de ayrışıyor
        $isTr = (substr($params['tld'], -3) == '.tr');
        $endpoint = $isTr ? '/transfers/tr/add' : '/transfers/tld/add';

        $api->request($endpoint, 'POST', [
            'domain' => $domainName,
            'auth' => $eppCode
        ]);

        return ['success' => true];

    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * NAMESERVER GÜNCELLEME
 */
function metunic_SaveNameservers($params)
{
    try {
        $api = new ApiClient($params['username'], $params['password'], $params['test_mode']);
        $domainName = $params['sld'] . '.' . $params['tld'];
        $serviceId = $api->getServiceIdByDomain($domainName);

        $isTr = (substr($params['tld'], -3) == '.tr');

        if ($isTr) {
            // TR Endpoint: NS'leri ayrı parametre olarak ister
            $nsParams = [
                'ns1' => $params['ns1'],
                'ns2' => $params['ns2'],
            ];
            if ($params['ns3']) $nsParams['ns3'] = $params['ns3'];
            if ($params['ns4']) $nsParams['ns4'] = $params['ns4'];
            if ($params['ns5']) $nsParams['ns5'] = $params['ns5'];

            $api->request("/services/{$serviceId}/tr/nameservers/change", 'PUT', $nsParams);

        } else {
            // TLD Endpoint: NS'leri array olarak ister (nameservers[])
            $nsList = [
                $params['ns1'],
                $params['ns2'],
                $params['ns3'],
                $params['ns4'],
                $params['ns5']
            ];
            // Boş olanları temizle
            $nsList = array_values(array_filter($nsList));

            $api->request("/services/{$serviceId}/tld/nameservers/change", 'PUT', [
                'nameservers' => $nsList
            ]);
        }

        return ['success' => true];

    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * NAMESERVER GETİRME
 */
function metunic_GetNameservers($params)
{
    try {
        $api = new ApiClient($params['username'], $params['password'], $params['test_mode']);
        $domainName = $params['sld'] . '.' . $params['tld'];
        $serviceId = $api->getServiceIdByDomain($domainName);

        $isTr = (substr($params['tld'], -3) == '.tr');
        $endpoint = $isTr 
            ? "/services/{$serviceId}/tr/nameservers/list" 
            : "/services/{$serviceId}/tld/nameservers/list";

        $response = $api->request($endpoint, 'GET');
        
        // Response'u parse et. Yapı genellikle result -> [{name: ns1...}, {name: ns2...}] şeklindedir
        // Ancak API dokümanı net bir örnek vermemiş, liste döndüğü kesin.
        $nsData = [];
        $i = 1;
        
        if (isset($response['result']) && is_array($response['result'])) {
            foreach ($response['result'] as $nsObj) {
                // Obje mi string mi dönüyor kontrolü
                $val = is_array($nsObj) ? ($nsObj['name'] ?? reset($nsObj)) : $nsObj;
                $nsData["ns{$i}"] = $val;
                $i++;
            }
        }

        return $nsData; // ['ns1' => '...', 'ns2' => '...']

    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * TRANSFER KİLİDİ (LOCK) YÖNETİMİ
 */
function metunic_SaveRegistrarLock($params)
{
    try {
        $api = new ApiClient($params['username'], $params['password'], $params['test_mode']);
        $domainName = $params['sld'] . '.' . $params['tld'];
        $serviceId = $api->getServiceIdByDomain($domainName);

        $isTr = (substr($params['tld'], -3) == '.tr');
        $typePath = $isTr ? 'tr' : 'tld';

        $action = $params['lockenabled'] == 'locked' ? 'lock' : 'unlock';
        
        $api->request("/services/{$serviceId}/{$typePath}/transfer/{$action}", 'POST');

        return ['success' => true];

    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * SYNC (Opsiyonel ama Önerilen)
 * Domain bitiş tarihlerini ve durumunu WHMCS ile eşitler.
 */
function metunic_Sync($params)
{
    try {
        $api = new ApiClient($params['username'], $params['password'], $params['test_mode']);
        $domainName = $params['sld'] . '.' . $params['tld'];
        
        // Servis ID bulamasa bile hata döndürmeden devam etsin, sync scripti patlamasın
        try {
            $serviceId = $api->getServiceIdByDomain($domainName);
        } catch (\Exception $ex) {
            return ['error' => 'Domain not found in Metunic'];
        }

        $isTr = (substr($params['tld'], -3) == '.tr');
        $endpoint = $isTr 
            ? "/services/{$serviceId}/tr/info" 
            : "/services/{$serviceId}/tld/info";

        $response = $api->request($endpoint, 'GET');
        $data = $response['result'] ?? [];

        // API'den dönen tarih formatı önemli. Genellikle "Y-m-d" veya timestamp döner.
        // Metunic dökümanı tarih formatını belirtmemiş, standart Y-m-d varsayıyoruz.
        // expiryDate veya endDate alanlarını kontrol etmelisin.
        
        $expiryDate = $data['endDate'] ?? $data['expirationDate'] ?? null;
        
        // Status mapping
        // Metunic 'active', 'suspended', 'pending' dönebilir.
        $active = false;
        if (isset($data['status']) && stripos($data['status'], 'active') !== false) {
            $active = true;
        }

        return [
            'expirydate' => $expiryDate, // Tarih formatı parse edilmeli gerekirse
            'active' => $active,
            'expired' => !$active,
        ];

    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}