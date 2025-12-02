<?php
/**
 * Metunic Registrar Module
 *
 * @copyright Copyright (c) WHMCS Limited 2017
 * @license https://www.whmcs.com/license/ WHMCS Eula
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Domains\DomainLookup\ResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;
use WHMCS\Module\Registrar\Metunicapi\ApiClient;

/**
 * Define module related metadata
 *
 * @return array
 */
function metunicapi_MetaData()
{
    return array(
        'DisplayName' => 'Metunic Registrar Module',
        'APIVersion' => '1.4.3',
    );
}

/**
 * Define registrar configuration options.
 *
 * @return array
 */
function metunicapi_getConfigArray()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Metunic Registrar Module',
        ],
        'APIUsername' => [
            'FriendlyName' => 'API Username',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Metunic reseller username',
        ],
        'APIKey' => [
            'FriendlyName' => 'API Password',
            'Type' => 'password',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Metunic reseller password',
        ],
        'TestMode' => [
            'FriendlyName' => 'Test Mode',
            'Type' => 'yesno',
            'Description' => 'Tick to enable',
        ],
    ];
}

/**
 * Register a domain.
 *
 * @param array $params common module parameters
 *
 * @return array
 */
function metunicapi_RegisterDomain($params)
{
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    $sld = $params['sld'];
    $tld = $params['tld'];
    $registrationPeriod = $params['regperiod'];

    $nameserver1 = $params['ns1'];
    $nameserver2 = $params['ns2'];
    $nameserver3 = $params['ns3'];
    $nameserver4 = $params['ns4'];
    $nameserver5 = $params['ns5'];

    $firstName = $params["firstname"];
    $lastName = $params["lastname"];
    $companyName = $params["companyname"];
    $email = $params["email"];
    $address1 = $params["address1"];
    $address2 = $params["address2"];
    $city = $params["city"];
    $state = $params["state"];
    $postcode = $params["postcode"];
    $countryCode = $params["countrycode"];
    $phoneNumberFormatted = $params["fullphonenumber"];

    $adminFirstName = $params["adminfirstname"];
    $adminLastName = $params["adminlastname"];
    $adminEmail = $params["adminemail"];
    $adminAddress1 = $params["adminaddress1"];
    $adminAddress2 = $params["adminaddress2"];
    $adminCity = $params["admincity"];
    $adminState = $params["adminstate"];
    $adminPostcode = $params["adminpostcode"];
    $adminCountry = $params["admincountry"];
    $adminPhoneNumberFormatted = $params["adminfullphonenumber"];

    try {
        $api = new ApiClient();
        $api->setBaseUrl($testMode ? 'https://api-test.metunic.com.tr/v1/' : 'https://api.metunic.com.tr/v1/');
        $domain = $sld . '.' . $tld;
        $isTr = (strpos($tld, 'tr') !== false);
        if ($isTr) {
            $registrantType = ($companyName ? 'organization' : 'individual');

            // Extract .tr specific fields from additionalfields
            $citizenId = '';
            $taxOffice = '';
            $taxNumber = '';

            if (isset($params['additionalfields'])) {
                foreach ($params['additionalfields'] as $key => $value) {
                    $k = strtolower($key);
                    if (strpos($k, 'identification') !== false || strpos($k, 'kimlik') !== false || strpos($k, 'tckn') !== false) {
                        $citizenId = $value;
                    }
                    if (strpos($k, 'office') !== false || strpos($k, 'dairesi') !== false) {
                        $taxOffice = $value;
                    }
                    if ((strpos($k, 'tax') !== false && strpos($k, 'number') !== false) || strpos($k, 'vergi') !== false) {
                         // exclude tax office if it matched 'tax'
                         if (strpos($k, 'office') === false && strpos($k, 'dairesi') === false) {
                             $taxNumber = $value;
                         }
                    }
                }
            }

            // Lookup IDs for Country and City
            $countryId = $api->getTrCountryId($countryCode, $auth);
            if (!$countryId) {
                // Fallback to code if lookup failed? Or throw?
                // Let's try to use the code as string if ID not found, maybe API handles it.
                // But better to throw or default.
                // Defaulting to 215 (Turkey) if code is TR might be safe but risky.
                // If lookup fails, maybe it IS a string field?
                // We'll pass what we have if lookup fails.
                $countryId = $countryCode;
            }

            $cityId = null;
            // Only try to lookup city if we have a valid numeric country ID
            if (is_numeric($countryId)) {
                $cityId = $api->getTrCityId($countryId, $city, $auth);
            }
            if (!$cityId) {
                $cityId = $city;
            }

            $paramsTr = array(
                'registrant_type' => $registrantType,
                'registrant_name' => trim($firstName . ' ' . $lastName),
                'registrant_organization' => $companyName,
                'registrant_address1' => $address1,
                'registrant_address2' => $address2,
                'registrant_country' => (string)$countryId,
                'registrant_city' => (string)$cityId,
                'registrant_postal_code' => $postcode,
                'registrant_phone' => $api->formatPhoneE164($phoneNumberFormatted),
                'registrant_email_address' => $email,
                'domain' => $domain,
                'ns1' => $nameserver1,
                'ns2' => $nameserver2,
                'ns3' => $nameserver3,
                'ns4' => $nameserver4,
                'ns5' => $nameserver5,
                'duration' => (int)$registrationPeriod,
            );

            if ($registrantType == 'individual') {
                if ($citizenId) $paramsTr['registrant_citizen_id'] = $citizenId;
            } else {
                if ($taxOffice) $paramsTr['registrant_tax_office'] = $taxOffice;
                if ($taxNumber) $paramsTr['registrant_tax_number'] = $taxNumber;
            }

            $api->call('POST', 'orders/tr', $paramsTr, null, $auth);
        } else {
            $contacts = array();
            $contacts['owner'] = $api->call('POST', 'contacts/add', array(
                'firstName' => $firstName,
                'lastName' => $lastName,
                'email' => $email,
                'address1' => $address1,
                'address2' => $address2,
                'city' => $city,
                'state' => $state,
                'zip' => $postcode,
                'country' => $countryCode,
                'phoneNumber' => $api->formatPhoneE164($phoneNumberFormatted),
            ), null, $auth);
            $contacts['admin'] = $api->call('POST', 'contacts/add', array(
                'firstName' => $adminFirstName,
                'lastName' => $adminLastName,
                'email' => $adminEmail,
                'address1' => $adminAddress1,
                'address2' => $adminAddress2,
                'city' => $adminCity,
                'state' => $adminState,
                'zip' => $adminPostcode,
                'country' => $adminCountry,
                'phoneNumber' => $api->formatPhoneE164($adminPhoneNumberFormatted),
            ), null, $auth);
            $contacts['tech'] = $contacts['admin'];
            $contacts['billing'] = $contacts['admin'];

            $ownerId = isset($contacts['owner']['id']) ? $contacts['owner']['id'] : (isset($contacts['owner']['contactId']) ? $contacts['owner']['contactId'] : null);
            $adminId = isset($contacts['admin']['id']) ? $contacts['admin']['id'] : (isset($contacts['admin']['contactId']) ? $contacts['admin']['contactId'] : null);
            $techId = isset($contacts['tech']['id']) ? $contacts['tech']['id'] : (isset($contacts['tech']['contactId']) ? $contacts['tech']['contactId'] : null);
            $billingId = isset($contacts['billing']['id']) ? $contacts['billing']['id'] : (isset($contacts['billing']['contactId']) ? $contacts['billing']['contactId'] : null);

            $api->call('POST', 'orders/tld', array(
                'domainName' => $domain,
                'ns1' => $nameserver1,
                'ns2' => $nameserver2,
                'ns3' => $nameserver3,
                'ns4' => $nameserver4,
                'ns5' => $nameserver5,
                'duration' => (int)$registrationPeriod,
                'owner' => $ownerId,
                'billing' => $billingId,
                'technical' => $techId,
                'admin' => $adminId,
            ), null, $auth);
        }
        return array('success' => true);
    } catch (\Exception $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * Initiate domain transfer.
 *
 * @param array $params common module parameters
 *
 * @return array
 */
function metunicapi_TransferDomain($params)
{
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $authPair = array('username' => $userIdentifier, 'password' => $apiKey);

    $sld = $params['sld'];
    $tld = $params['tld'];
    $eppCode = $params['eppcode'];

    try {
        $api = new ApiClient();
        $api->setBaseUrl($testMode ? 'https://api-test.metunic.com.tr/v1/' : 'https://api.metunic.com.tr/v1/');
        $domain = $sld . '.' . $tld;
        $isTr = (strpos($tld, 'tr') !== false);
        if ($isTr) {
            $api->call('POST', 'transfers/tr/add', array(
                'domain' => $domain,
                'auth' => $eppCode,
            ), null, $authPair);
        } else {
            $api->call('POST', 'transfers/tld/add', array(
                'domain' => $domain,
                'auth' => $eppCode,
            ), null, $authPair);
        }
        return array('success' => true);
    } catch (\Exception $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * Renew a domain.
 *
 * @param array $params common module parameters
 *
 * @return array
 */
function metunicapi_RenewDomain($params)
{
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    $sld = $params['sld'];
    $tld = $params['tld'];
    $registrationPeriod = $params['regperiod'];

    try {
        $api = new ApiClient();
        $api->setBaseUrl($testMode ? 'https://api-test.metunic.com.tr/v1/' : 'https://api.metunic.com.tr/v1/');
        $domain = $sld . '.' . $tld;
        $serviceId = $api->queryServiceIdByDomain($domain, $auth);
        $api->call('POST', 'services/' . $serviceId . '/renew-duration', array(
            'duration' => (int)$registrationPeriod,
        ), null, $auth);
        return array('success' => true);
    } catch (\Exception $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * Fetch current nameservers.
 *
 * @param array $params common module parameters
 *
 * @return array
 */
function metunicapi_GetNameservers($params)
{
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    $sld = $params['sld'];
    $tld = $params['tld'];

    try {
        $api = new ApiClient();
        $api->setBaseUrl($testMode ? 'https://api-test.metunic.com.tr/v1/' : 'https://api.metunic.com.tr/v1/');
        $domain = $sld . '.' . $tld;
        $serviceId = $api->queryServiceIdByDomain($domain, $auth);
        $isTr = (strpos($tld, 'tr') !== false);
        if ($isTr) {
            $res = $api->call('GET', 'services/' . $serviceId . '/tr/nameservers/list', array(), null, $auth);
        } else {
            $res = $api->call('GET', 'services/' . $serviceId . '/tld/nameservers/list', array(), null, $auth);
        }
        return array(
            'ns1' => isset($res['nameservers'][0]) ? $res['nameservers'][0] : '',
            'ns2' => isset($res['nameservers'][1]) ? $res['nameservers'][1] : '',
            'ns3' => isset($res['nameservers'][2]) ? $res['nameservers'][2] : '',
            'ns4' => isset($res['nameservers'][3]) ? $res['nameservers'][3] : '',
            'ns5' => isset($res['nameservers'][4]) ? $res['nameservers'][4] : '',
        );
    } catch (\Exception $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * Save nameserver changes.
 *
 * @param array $params common module parameters
 *
 * @return array
 */
function metunicapi_SaveNameservers($params)
{
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    $sld = $params['sld'];
    $tld = $params['tld'];

    $nameserver1 = $params['ns1'];
    $nameserver2 = $params['ns2'];
    $nameserver3 = $params['ns3'];
    $nameserver4 = $params['ns4'];
    $nameserver5 = $params['ns5'];

    try {
        $api = new ApiClient();
        $api->setBaseUrl($testMode ? 'https://api-test.metunic.com.tr/v1/' : 'https://api.metunic.com.tr/v1/');
        $domain = $sld . '.' . $tld;
        $serviceId = $api->queryServiceIdByDomain($domain, $auth);
        $isTr = (strpos($tld, 'tr') !== false);
        if ($isTr) {
            $paramsTr = array(
                'ns1' => $nameserver1,
                'ns2' => $nameserver2,
                'ns3' => $nameserver3,
                'ns4' => $nameserver4,
                'ns5' => $nameserver5,
            );
            $api->call('PUT', 'services/' . $serviceId . '/tr/nameservers/change', $paramsTr, null, $auth);
        } else {
            $api->call('PUT', 'services/' . $serviceId . '/tld/nameservers/change', array(
                'nameservers[]' => array_filter(array($nameserver1, $nameserver2, $nameserver3, $nameserver4, $nameserver5)),
            ), null, $auth);
        }
        return array('success' => true);
    } catch (\Exception $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * Get the current WHOIS Contact Information.
 *
 * @param array $params common module parameters
 *
 * @return array
 */
function metunicapi_GetContactDetails($params)
{
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    $sld = $params['sld'];
    $tld = $params['tld'];

    try {
        $api = new ApiClient();
        $api->setBaseUrl($testMode ? 'https://api-test.metunic.com.tr/v1/' : 'https://api.metunic.com.tr/v1/');
        $domain = $sld . '.' . $tld;
        $serviceId = $api->queryServiceIdByDomain($domain, $auth);
        $isTr = (strpos($tld, 'tr') !== false);
        if ($isTr) {
            $res = $api->call('GET', 'services/' . $serviceId . '/tr/info', array(), null, $auth);
            $admin = isset($res['admin']) ? $res['admin'] : array();
            $tech = isset($res['tech']) ? $res['tech'] : array();
            $billing = isset($res['billing']) ? $res['billing'] : array();
            return array(
                'Admin' => array(
                    'First Name' => isset($admin['name']) ? $admin['name'] : '',
                    'Email Address' => isset($admin['email']) ? $admin['email'] : '',
                ),
                'Technical' => array(
                    'First Name' => isset($tech['name']) ? $tech['name'] : '',
                    'Email Address' => isset($tech['email']) ? $tech['email'] : '',
                ),
                'Billing' => array(
                    'First Name' => isset($billing['name']) ? $billing['name'] : '',
                    'Email Address' => isset($billing['email']) ? $billing['email'] : '',
                ),
            );
        } else {
            $res = $api->call('GET', 'services/' . $serviceId . '/tld/contacts', array(), null, $auth);
            $mapFn = function($c){
                return array(
                    'First Name' => isset($c['firstName']) ? $c['firstName'] : '',
                    'Last Name' => isset($c['lastName']) ? $c['lastName'] : '',
                    'Company Name' => isset($c['company']) ? $c['company'] : '',
                    'Email Address' => isset($c['email']) ? $c['email'] : '',
                    'Address 1' => isset($c['address1']) ? $c['address1'] : '',
                    'Address 2' => isset($c['address2']) ? $c['address2'] : '',
                    'City' => isset($c['city']) ? $c['city'] : '',
                    'State' => isset($c['state']) ? $c['state'] : '',
                    'Postcode' => isset($c['zip']) ? $c['zip'] : '',
                    'Country' => isset($c['country']) ? $c['country'] : '',
                    'Phone Number' => isset($c['phoneNumber']) ? $c['phoneNumber'] : '',
                );
            };
            return array(
                'Registrant' => $mapFn(isset($res['registrant']) ? $res['registrant'] : array()),
                'Technical' => $mapFn(isset($res['technical']) ? $res['technical'] : array()),
                'Billing' => $mapFn(isset($res['billing']) ? $res['billing'] : array()),
                'Admin' => $mapFn(isset($res['admin']) ? $res['admin'] : array()),
            );
        }
    } catch (\Exception $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * Update the WHOIS Contact Information for a given domain.
 *
 * @param array $params common module parameters
 *
 * @return array
 */
function metunicapi_SaveContactDetails($params)
{
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    $sld = $params['sld'];
    $tld = $params['tld'];

    $contactDetails = $params['contactdetails'];

    try {
        $api = new ApiClient();
        $api->setBaseUrl($testMode ? 'https://api-test.metunic.com.tr/v1/' : 'https://api.metunic.com.tr/v1/');
        $domain = $sld . '.' . $tld;
        $serviceId = $api->queryServiceIdByDomain($domain, $auth);
        $isTr = (strpos($tld, 'tr') !== false);
        if ($isTr) {
            $body = json_encode(array(
                'admin' => array(
                    'name' => $contactDetails['Admin']['First Name'],
                    'email' => $contactDetails['Admin']['Email Address'],
                ),
                'tech' => array(
                    'name' => $contactDetails['Technical']['First Name'],
                    'email' => $contactDetails['Technical']['Email Address'],
                ),
                'billing' => array(
                    'name' => $contactDetails['Billing']['First Name'],
                    'email' => $contactDetails['Billing']['Email Address'],
                ),
            ));
            $api->call('PUT', 'services/' . $serviceId . '/tr/contacts/update', array(), $body, $auth);
        } else {
            $current = $api->call('GET', 'services/' . $serviceId . '/tld/contacts', array(), null, $auth);
            $types = array('admin' => 'Admin', 'technical' => 'Technical', 'billing' => 'Billing', 'registrant' => 'Registrant');
            foreach ($types as $typeKey => $whmcsKey) {
                if (isset($current[$typeKey]['id'])) {
                    $api->call('PUT', 'contacts/' . $current[$typeKey]['id'] . '/edit', array(
                        'firstName' => $contactDetails[$whmcsKey]['First Name'],
                        'lastName' => isset($contactDetails[$whmcsKey]['Last Name']) ? $contactDetails[$whmcsKey]['Last Name'] : '',
                        'email' => $contactDetails[$whmcsKey]['Email Address'],
                        'address1' => isset($contactDetails[$whmcsKey]['Address 1']) ? $contactDetails[$whmcsKey]['Address 1'] : '',
                        'address2' => isset($contactDetails[$whmcsKey]['Address 2']) ? $contactDetails[$whmcsKey]['Address 2'] : '',
                        'city' => isset($contactDetails[$whmcsKey]['City']) ? $contactDetails[$whmcsKey]['City'] : '',
                        'state' => isset($contactDetails[$whmcsKey]['State']) ? $contactDetails[$whmcsKey]['State'] : '',
                        'zip' => isset($contactDetails[$whmcsKey]['Postcode']) ? $contactDetails[$whmcsKey]['Postcode'] : '',
                        'country' => isset($contactDetails[$whmcsKey]['Country']) ? $contactDetails[$whmcsKey]['Country'] : '',
                        'phoneNumber' => isset($contactDetails[$whmcsKey]['Phone Number']) ? $api->formatPhoneE164($contactDetails[$whmcsKey]['Phone Number']) : '',
                    ), null, $auth);
                }
            }
        }
        return array('success' => true);
    } catch (\Exception $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * Check Domain Availability.
 *
 * @param array $params common module parameters
 *
 * @return \WHMCS\Domains\DomainLookup\ResultsList
 */
function metunicapi_CheckAvailability($params)
{
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    $searchTerm = $params['searchTerm'];
    $tldsToInclude = $params['tldsToInclude'];

    try {
        $api = new ApiClient();
        $api->setBaseUrl($testMode ? 'https://api-test.metunic.com.tr/v1/' : 'https://api.metunic.com.tr/v1/');
        $results = new ResultsList();
        foreach ($tldsToInclude as $tld) {
            $domain = $searchTerm . '.' . $tld;
            // Catch error for each domain to not break loop
            try {
                $api->call('GET', 'domains/check', array('domainName' => $domain), null, $auth);
                $envelope = $api->getLastEnvelope();
                $searchResult = new SearchResult($searchTerm, $tld);
                $code = isset($envelope['messageCode']) ? (int)$envelope['messageCode'] : null;
                if ($code === 12) {
                    $searchResult->setStatus(SearchResult::STATUS_NOT_REGISTERED);
                } elseif ($code === 8) {
                    $searchResult->setStatus(SearchResult::STATUS_REGISTERED);
                } else {
                    $searchResult->setStatus(SearchResult::STATUS_TLD_NOT_SUPPORTED);
                }
                $results->append($searchResult);
            } catch (\Exception $e) {
                 // Ignore individual failures
            }
        }
        return $results;
    } catch (\Exception $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * Get registrar lock status.
 *
 * @param array $params common module parameters
 *
 * @return string|array Lock status or error message
 */
function metunicapi_GetRegistrarLock($params)
{
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    $sld = $params['sld'];
    $tld = $params['tld'];

    try {
        $api = new ApiClient();
        $api->setBaseUrl($testMode ? 'https://api-test.metunic.com.tr/v1/' : 'https://api.metunic.com.tr/v1/');
        $domain = $sld . '.' . $tld;
        $serviceId = $api->queryServiceIdByDomain($domain, $auth);
        $isTr = (strpos($tld, 'tr') !== false);
        $res = $api->call('GET', 'services/' . $serviceId . ($isTr ? '/tr/info' : '/tld/info'), array(), null, $auth);
        $statusStr = isset($res['status']) ? $res['status'] : '';
        if (strpos($statusStr, 'clientTransferProhibited') !== false) {
            return 'locked';
        }
        return 'unlocked';
    } catch (\Exception $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * Set registrar lock status.
 *
 * @param array $params common module parameters
 *
 * @return array
 */
function metunicapi_SaveRegistrarLock($params)
{
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    $sld = $params['sld'];
    $tld = $params['tld'];
    $lockStatus = $params['lockenabled'];

    try {
        $api = new ApiClient();
        $api->setBaseUrl($testMode ? 'https://api-test.metunic.com.tr/v1/' : 'https://api.metunic.com.tr/v1/');
        $domain = $sld . '.' . $tld;
        $serviceId = $api->queryServiceIdByDomain($domain, $auth);
        $isTr = (strpos($tld, 'tr') !== false);
        if ($lockStatus == 'locked') {
            $api->call('POST', 'services/' . $serviceId . ($isTr ? '/tr/transfer/lock' : '/tld/transfer/lock'), array(), null, $auth);
        } else {
            $api->call('POST', 'services/' . $serviceId . ($isTr ? '/tr/transfer/unlock' : '/tld/transfer/unlock'), array(), null, $auth);
        }
        return array('success' => 'success');
    } catch (\Exception $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * Register a Nameserver.
 *
 * @param array $params common module parameters
 *
 * @return array
 */
function metunicapi_RegisterNameserver($params)
{
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    $sld = $params['sld'];
    $tld = $params['tld'];
    $nameserver = $params['nameserver'];
    $ipAddress = $params['ipaddress'];

    try {
        $api = new ApiClient();
        $api->setBaseUrl($testMode ? 'https://api-test.metunic.com.tr/v1/' : 'https://api.metunic.com.tr/v1/');
        $domain = $sld . '.' . $tld;

        $hostnamePart = str_replace("." . $domain, "", $nameserver);
        $serviceId = $api->queryServiceIdByDomain($domain, $auth);

        // Assuming TLD support only as per spec analysis
        $api->call('POST', 'services/' . $serviceId . '/tld/subns/add', array(
            'hostname_part' => $hostnamePart,
            'ip' => $ipAddress,
        ), null, $auth);

        return array('success' => 'success');
    } catch (\Exception $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * Modify a Nameserver.
 *
 * @param array $params common module parameters
 *
 * @return array
 */
function metunicapi_ModifyNameserver($params)
{
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    $sld = $params['sld'];
    $tld = $params['tld'];
    $nameserver = $params['nameserver'];
    $newIpAddress = $params['newipaddress'];

    try {
        $api = new ApiClient();
        $api->setBaseUrl($testMode ? 'https://api-test.metunic.com.tr/v1/' : 'https://api.metunic.com.tr/v1/');
        $domain = $sld . '.' . $tld;

        $hostnamePart = str_replace("." . $domain, "", $nameserver);
        $serviceId = $api->queryServiceIdByDomain($domain, $auth);

        $api->call('PUT', 'services/' . $serviceId . '/tld/subns/edit', array(
            'hostname_part' => $hostnamePart,
            'ip' => $newIpAddress,
        ), null, $auth);

        return array('success' => 'success');
    } catch (\Exception $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * Delete a Nameserver.
 *
 * @param array $params common module parameters
 *
 * @return array
 */
function metunicapi_DeleteNameserver($params)
{
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    $sld = $params['sld'];
    $tld = $params['tld'];
    $nameserver = $params['nameserver'];

    try {
        $api = new ApiClient();
        $api->setBaseUrl($testMode ? 'https://api-test.metunic.com.tr/v1/' : 'https://api.metunic.com.tr/v1/');
        $domain = $sld . '.' . $tld;

        $hostnamePart = str_replace("." . $domain, "", $nameserver);
        $serviceId = $api->queryServiceIdByDomain($domain, $auth);

        // We need the IP to delete. List existing to find it.
        $res = $api->call('GET', 'services/' . $serviceId . '/tld/subns', array(), null, $auth);
        $ip = null;
        if (is_array($res)) {
            // response structure not fully defined in provided text, checking if list is root array or inside a key
            // Assuming array of objects with hostname/ip or something similar
            $list = isset($res['subNameservers']) ? $res['subNameservers'] : (isset($res['data']) ? $res['data'] : $res);

            // If result is directly the list (based on other endpoints it might be)
            // But usually wrapped in 'result'. ApiClient extracts 'result'.

            if (is_array($list)) {
                foreach ($list as $subns) {
                    // Check format. assuming subns object has name or hostname
                     $subName = isset($subns['name']) ? $subns['name'] : (isset($subns['hostname']) ? $subns['hostname'] : '');
                     // Hostname might be full or part.
                     if ($subName == $nameserver || $subName == $hostnamePart) {
                         $ip = isset($subns['ip']) ? $subns['ip'] : null;
                         break;
                     }
                }
            }
        }

        if (!$ip) {
             throw new \Exception("Could not find IP for nameserver $nameserver to delete.");
        }

        $api->call('DELETE', 'services/' . $serviceId . '/tld/subns/delete', array(
            'hostname_part' => $hostnamePart,
            'ip' => $ip,
        ), null, $auth);

        return array('success' => 'success');
    } catch (\Exception $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * Sync Domain Status & Expiration Date.
 *
 * @param array $params common module parameters
 *
 * @return array
 */
function metunicapi_Sync($params)
{
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    $sld = $params['sld'];
    $tld = $params['tld'];

    try {
        $api = new ApiClient();
        $api->setBaseUrl($testMode ? 'https://api-test.metunic.com.tr/v1/' : 'https://api.metunic.com.tr/v1/');
        $domain = $sld . '.' . $tld;
        $serviceId = $api->queryServiceIdByDomain($domain, $auth);

        $isTr = (strpos($tld, 'tr') !== false);
        $res = $api->call('GET', 'services/' . $serviceId . ($isTr ? '/tr/info' : '/tld/info'), array(), null, $auth);

        // Try to find expiration date
        $expiry = null;
        $candidates = array('expiryDate', 'expirationDate', 'endDate', 'validUntil', 'expirydate', 'expire');
        foreach ($candidates as $key) {
            if (isset($res[$key]) && $res[$key]) {
                $expiry = $res[$key];
                break;
            }
        }

        // Status check
        $active = false;
        // If we found an expiry date, and it's in future, it's active.
        if ($expiry) {
             $expiryDate = date('Y-m-d', strtotime($expiry));
             $active = true; // defaulting to true if we can read it. WHMCS handles expired logic based on date.
        } else {
             $expiryDate = null;
        }

        return array(
            'expirydate' => $expiryDate,
            'active' => $active,
        );
    } catch (\Exception $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * Incoming Domain Transfer Sync.
 *
 * @param array $params common module parameters
 *
 * @return array
 */
function metunicapi_TransferSync($params)
{
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    $sld = $params['sld'];
    $tld = $params['tld'];

    try {
        $api = new ApiClient();
        $api->setBaseUrl($testMode ? 'https://api-test.metunic.com.tr/v1/' : 'https://api.metunic.com.tr/v1/');
        $domain = $sld . '.' . $tld;
        $serviceId = $api->queryServiceIdByDomain($domain, $auth);

        $res = $api->call('GET', 'transfers/tld/' . $serviceId . '/status', array(), null, $auth);

        // Assuming response has 'status' or similar.
        // If success
        if (isset($res['status']) && strtolower($res['status']) == 'completed') {
             return array('completed' => true);
        }
        // If failed
        if (isset($res['status']) && (strtolower($res['status']) == 'failed' || strtolower($res['status']) == 'cancelled')) {
             return array('failed' => true, 'reason' => isset($res['reason']) ? $res['reason'] : 'Transfer failed');
        }

        return array();
    } catch (\Exception $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * Enable/Disable ID Protection.
 *
 * @param array $params common module parameters
 *
 * @return array
 */
function metunicapi_IDProtectToggle($params)
{
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    $sld = $params['sld'];
    $tld = $params['tld'];
    $protectEnable = (bool) $params['protectenable'];

    try {
        $api = new ApiClient();
        $api->setBaseUrl($testMode ? 'https://api-test.metunic.com.tr/v1/' : 'https://api.metunic.com.tr/v1/');
        $domain = $sld . '.' . $tld;
        $serviceId = $api->queryServiceIdByDomain($domain, $auth);

        // Spec only lists TLD endpoint. TR might not support it.
        if (strpos($tld, 'tr') !== false) {
             return array('error' => 'ID Protection not supported for .tr domains via this module');
        }

        $api->call('POST', 'services/' . $serviceId . '/tld/whois-privacy', array(
            'status' => $protectEnable ? 'on' : 'off'
        ), null, $auth);

        return array('success' => 'success');

    } catch (\Exception $e) {
        return array('error' => $e->getMessage());
    }
}
