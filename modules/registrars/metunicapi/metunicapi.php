<?php
/**
 * WHMCS SDK Sample Registrar Module
 *
 * Registrar Modules allow you to create modules that allow for domain
 * registration, management, transfers, and other functionality within
 * WHMCS.
 *
 * This sample file demonstrates how a registrar module for WHMCS should
 * be structured and exercises supported functionality.
 *
 * Registrar Modules are stored in a unique directory within the
 * modules/registrars/ directory that matches the module's unique name.
 * This name should be all lowercase, containing only letters and numbers,
 * and always start with a letter.
 *
 * Within the module itself, all functions must be prefixed with the module
 * filename, followed by an underscore, and then the function name. For
 * example this file, the filename is "registrarmodule.php" and therefore all
 * function begin "registrarmodule_".
 *
 * If your module or third party API does not support a given function, you
 * should not define the function within your module. WHMCS recommends that
 * all registrar modules implement Register, Transfer, Renew, GetNameservers,
 * SaveNameservers, GetContactDetails & SaveContactDetails.
 *
 * For more information, please refer to the online documentation.
 *
 * @see https://developers.whmcs.com/domain-registrars/
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

// Require any libraries needed for the module to function.
// require_once __DIR__ . '/path/to/library/loader.php';
//
// Also, perform any initialization required by the service's library.

/**
 * Define module related metadata
 *
 * Provide some module information including the display name and API Version to
 * determine the method of decoding the input values.
 *
 * @return array
 */
function metunicapi_MetaData()
{
    return array(
        'DisplayName' => 'Metunic Registrar Module',
        'APIVersion' => '1.1',
    );
}

/**
 * Define registrar configuration options.
 *
 * The values you return here define what configuration options
 * we store for the module. These values are made available to
 * each module function.
 *
 * You can store an unlimited number of configuration settings.
 * The following field types are supported:
 *  * Text
 *  * Password
 *  * Yes/No Checkboxes
 *  * Dropdown Menus
 *  * Radio Buttons
 *  * Text Areas
 *
 * @return array
 */
function metunicapi_getConfigArray()
{
    return [
        // Friendly display name for the module
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Metunic Registrar Module',
        ],
        // a text field type allows for single line text input
        'APIUsername' => [
            'FriendlyName' => 'API Username',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Metunic reseller username',
        ],
        // a password field type allows for masked text input
        'APIKey' => [
            'FriendlyName' => 'API Password',
            'Type' => 'password',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Metunic reseller password',
        ],
        // the yesno field type displays a single checkbox option
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
 * Attempt to register a domain with the domain registrar.
 *
 * This is triggered when the following events occur:
 * * Payment received for a domain registration order
 * * When a pending domain registration order is accepted
 * * Upon manual request by an admin user
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function metunicapi_RegisterDomain($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    // registration parameters
    $sld = $params['sld'];
    $tld = $params['tld'];
    $registrationPeriod = $params['regperiod'];

    /**
     * Nameservers.
     *
     * If purchased with web hosting, values will be taken from the
     * assigned web hosting server. Otherwise uses the values specified
     * during the order process.
     */
    $nameserver1 = $params['ns1'];
    $nameserver2 = $params['ns2'];
    $nameserver3 = $params['ns3'];
    $nameserver4 = $params['ns4'];
    $nameserver5 = $params['ns5'];

    // registrant information
    $firstName = $params["firstname"];
    $lastName = $params["lastname"];
    $fullName = $params["fullname"]; // First name and last name combined
    $companyName = $params["companyname"];
    $email = $params["email"];
    $address1 = $params["address1"];
    $address2 = $params["address2"];
    $city = $params["city"];
    $state = $params["state"]; // eg. TX
    $stateFullName = $params["fullstate"]; // eg. Texas
    $postcode = $params["postcode"]; // Postcode/Zip code
    $countryCode = $params["countrycode"]; // eg. GB
    $countryName = $params["countryname"]; // eg. United Kingdom
    $phoneNumber = $params["phonenumber"]; // Phone number as the user provided it
    $phoneCountryCode = $params["phonecc"]; // Country code determined based on country
    $phoneNumberFormatted = $params["fullphonenumber"]; // Format: +CC.xxxxxxxxxxxx

    /**
     * Admin contact information.
     *
     * Defaults to the same as the client information. Can be configured
     * to use the web hosts details if the `Use Clients Details` option
     * is disabled in Setup > General Settings > Domains.
     */
    $adminFirstName = $params["adminfirstname"];
    $adminLastName = $params["adminlastname"];
    $adminCompanyName = $params["admincompanyname"];
    $adminEmail = $params["adminemail"];
    $adminAddress1 = $params["adminaddress1"];
    $adminAddress2 = $params["adminaddress2"];
    $adminCity = $params["admincity"];
    $adminState = $params["adminstate"]; // eg. TX
    $adminStateFull = $params["adminfullstate"]; // eg. Texas
    $adminPostcode = $params["adminpostcode"]; // Postcode/Zip code
    $adminCountry = $params["admincountry"]; // eg. GB
    $adminPhoneNumber = $params["adminphonenumber"]; // Phone number as the user provided it
    $adminPhoneNumberFormatted = $params["adminfullphonenumber"]; // Format: +CC.xxxxxxxxxxxx

    // domain addon purchase status
    $enableDnsManagement = (bool) $params['dnsmanagement'];
    $enableEmailForwarding = (bool) $params['emailforwarding'];
    $enableIdProtection = (bool) $params['idprotection'];

    /**
     * Premium domain parameters.
     *
     * Premium domains enabled informs you if the admin user has enabled
     * the selling of premium domain names. If this domain is a premium name,
     * `premiumCost` will contain the cost price retrieved at the time of
     * the order being placed. The premium order should only be processed
     * if the cost price now matches the previously fetched amount.
     */
    $premiumDomainsEnabled = (bool) $params['premiumEnabled'];
    $premiumDomainsCost = $params['premiumCost'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'years' => $registrationPeriod,
        'nameservers' => array(
            'ns1' => $nameserver1,
            'ns2' => $nameserver2,
            'ns3' => $nameserver3,
            'ns4' => $nameserver4,
            'ns5' => $nameserver5,
        ),
        'contacts' => array(
            'registrant' => array(
                'firstname' => $firstName,
                'lastname' => $lastName,
                'companyname' => $companyName,
                'email' => $email,
                'address1' => $address1,
                'address2' => $address2,
                'city' => $city,
                'state' => $state,
                'zipcode' => $postcode,
                'country' => $countryCode,
                'phonenumber' => $phoneNumberFormatted,
            ),
            'tech' => array(
                'firstname' => $adminFirstName,
                'lastname' => $adminLastName,
                'companyname' => $adminCompanyName,
                'email' => $adminEmail,
                'address1' => $adminAddress1,
                'address2' => $adminAddress2,
                'city' => $adminCity,
                'state' => $adminState,
                'zipcode' => $adminPostcode,
                'country' => $adminCountry,
                'phonenumber' => $adminPhoneNumberFormatted,
            ),
        ),
        'dnsmanagement' => $enableDnsManagement,
        'emailforwarding' => $enableEmailForwarding,
        'idprotection' => $enableIdProtection,
    );

    if ($premiumDomainsEnabled && $premiumDomainsCost) {
        $postfields['accepted_premium_cost'] = $premiumDomainsCost;
    }

    try {
        $api = new ApiClient();
        $api->setBaseUrl($testMode ? 'https://api-test.metunic.com.tr/v1/' : 'https://api.metunic.com.tr/v1/');
        $domain = $sld . '.' . $tld;
        $isTr = (strpos($tld, 'tr') !== false);
        if ($isTr) {
            $registrantType = ($companyName ? 'organization' : 'individual');
            $paramsTr = array(
                'registrant_type' => $registrantType,
                'registrant_name' => trim($firstName . ' ' . $lastName),
                'registrant_organization' => $companyName,
                'registrant_address1' => $address1,
                'registrant_address2' => $address2,
                'registrant_country' => $countryCode,
                'registrant_city' => $city,
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
 * Attempt to create a domain transfer request for a given domain.
 *
 * This is triggered when the following events occur:
 * * Payment received for a domain transfer order
 * * When a pending domain transfer order is accepted
 * * Upon manual request by an admin user
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function metunicapi_TransferDomain($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $authPair = array('username' => $userIdentifier, 'password' => $apiKey);

    // registration parameters
    $sld = $params['sld'];
    $tld = $params['tld'];
    $registrationPeriod = $params['regperiod'];
    $eppCode = $params['eppcode'];

    /**
     * Nameservers.
     *
     * If purchased with web hosting, values will be taken from the
     * assigned web hosting server. Otherwise uses the values specified
     * during the order process.
     */
    $nameserver1 = $params['ns1'];
    $nameserver2 = $params['ns2'];
    $nameserver3 = $params['ns3'];
    $nameserver4 = $params['ns4'];
    $nameserver5 = $params['ns5'];

    // registrant information
    $firstName = $params["firstname"];
    $lastName = $params["lastname"];
    $fullName = $params["fullname"]; // First name and last name combined
    $companyName = $params["companyname"];
    $email = $params["email"];
    $address1 = $params["address1"];
    $address2 = $params["address2"];
    $city = $params["city"];
    $state = $params["state"]; // eg. TX
    $stateFullName = $params["fullstate"]; // eg. Texas
    $postcode = $params["postcode"]; // Postcode/Zip code
    $countryCode = $params["countrycode"]; // eg. GB
    $countryName = $params["countryname"]; // eg. United Kingdom
    $phoneNumber = $params["phonenumber"]; // Phone number as the user provided it
    $phoneCountryCode = $params["phonecc"]; // Country code determined based on country
    $phoneNumberFormatted = $params["fullphonenumber"]; // Format: +CC.xxxxxxxxxxxx

    /**
     * Admin contact information.
     *
     * Defaults to the same as the client information. Can be configured
     * to use the web hosts details if the `Use Clients Details` option
     * is disabled in Setup > General Settings > Domains.
     */
    $adminFirstName = $params["adminfirstname"];
    $adminLastName = $params["adminlastname"];
    $adminCompanyName = $params["admincompanyname"];
    $adminEmail = $params["adminemail"];
    $adminAddress1 = $params["adminaddress1"];
    $adminAddress2 = $params["adminaddress2"];
    $adminCity = $params["admincity"];
    $adminState = $params["adminstate"]; // eg. TX
    $adminStateFull = $params["adminfullstate"]; // eg. Texas
    $adminPostcode = $params["adminpostcode"]; // Postcode/Zip code
    $adminCountry = $params["admincountry"]; // eg. GB
    $adminPhoneNumber = $params["adminphonenumber"]; // Phone number as the user provided it
    $adminPhoneNumberFormatted = $params["adminfullphonenumber"]; // Format: +CC.xxxxxxxxxxxx

    // domain addon purchase status
    $enableDnsManagement = (bool) $params['dnsmanagement'];
    $enableEmailForwarding = (bool) $params['emailforwarding'];
    $enableIdProtection = (bool) $params['idprotection'];

    /**
     * Premium domain parameters.
     *
     * Premium domains enabled informs you if the admin user has enabled
     * the selling of premium domain names. If this domain is a premium name,
     * `premiumCost` will contain the cost price retrieved at the time of
     * the order being placed. The premium order should only be processed
     * if the cost price now matches that previously fetched amount.
     */
    $premiumDomainsEnabled = (bool) $params['premiumEnabled'];
    $premiumDomainsCost = $params['premiumCost'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'eppcode' => $eppCode,
        'nameservers' => array(
            'ns1' => $nameserver1,
            'ns2' => $nameserver2,
            'ns3' => $nameserver3,
            'ns4' => $nameserver4,
            'ns5' => $nameserver5,
        ),
        'years' => $registrationPeriod,
        'contacts' => array(
            'registrant' => array(
                'firstname' => $firstName,
                'lastname' => $lastName,
                'companyname' => $companyName,
                'email' => $email,
                'address1' => $address1,
                'address2' => $address2,
                'city' => $city,
                'state' => $state,
                'zipcode' => $postcode,
                'country' => $countryCode,
                'phonenumber' => $phoneNumberFormatted,
            ),
            'tech' => array(
                'firstname' => $adminFirstName,
                'lastname' => $adminLastName,
                'companyname' => $adminCompanyName,
                'email' => $adminEmail,
                'address1' => $adminAddress1,
                'address2' => $adminAddress2,
                'city' => $adminCity,
                'state' => $adminState,
                'zipcode' => $adminPostcode,
                'country' => $adminCountry,
                'phonenumber' => $adminPhoneNumberFormatted,
            ),
        ),
        'dnsmanagement' => $enableDnsManagement,
        'emailforwarding' => $enableEmailForwarding,
        'idprotection' => $enableIdProtection,
    );

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
 * Attempt to renew/extend a domain for a given number of years.
 *
 * This is triggered when the following events occur:
 * * Payment received for a domain renewal order
 * * When a pending domain renewal order is accepted
 * * Upon manual request by an admin user
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function metunicapi_RenewDomain($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    // registration parameters
    $sld = $params['sld'];
    $tld = $params['tld'];
    $registrationPeriod = $params['regperiod'];

    // domain addon purchase status
    $enableDnsManagement = (bool) $params['dnsmanagement'];
    $enableEmailForwarding = (bool) $params['emailforwarding'];
    $enableIdProtection = (bool) $params['idprotection'];

    /**
     * Premium domain parameters.
     *
     * Premium domains enabled informs you if the admin user has enabled
     * the selling of premium domain names. If this domain is a premium name,
     * `premiumCost` will contain the cost price retrieved at the time of
     * the order being placed. A premium renewal should only be processed
     * if the cost price now matches that previously fetched amount.
     */
    $premiumDomainsEnabled = (bool) $params['premiumEnabled'];
    $premiumDomainsCost = $params['premiumCost'];

    // Build post data.
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'years' => $registrationPeriod,
        'dnsmanagement' => $enableDnsManagement,
        'emailforwarding' => $enableEmailForwarding,
        'idprotection' => $enableIdProtection,
    );

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
 * This function should return an array of nameservers for a given domain.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function metunicapi_GetNameservers($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];
    $registrationPeriod = $params['regperiod'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
    );

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
 * This function should submit a change of nameservers request to the
 * domain registrar.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function metunicapi_SaveNameservers($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // submitted nameserver values
    $nameserver1 = $params['ns1'];
    $nameserver2 = $params['ns2'];
    $nameserver3 = $params['ns3'];
    $nameserver4 = $params['ns4'];
    $nameserver5 = $params['ns5'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'nameserver1' => $nameserver1,
        'nameserver2' => $nameserver2,
        'nameserver3' => $nameserver3,
        'nameserver4' => $nameserver4,
        'nameserver5' => $nameserver5,
    );

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
 * Should return a multi-level array of the contacts and name/address
 * fields that be modified.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function metunicapi_GetContactDetails($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);


    // domain parameters
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
 * Called when a change of WHOIS Information is requested within WHMCS.
 * Receives an array matching the format provided via the `GetContactDetails`
 * method with the values from the users input.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function metunicapi_SaveContactDetails($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // whois information
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
 * Determine if a domain or group of domains are available for
 * registration or transfer.
 *
 * @param array $params common module parameters
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @see \WHMCS\Domains\DomainLookup\SearchResult
 * @see \WHMCS\Domains\DomainLookup\ResultsList
 *
 * @throws Exception Upon domain availability check failure.
 *
 * @return \WHMCS\Domains\DomainLookup\ResultsList An ArrayObject based collection of \WHMCS\Domains\DomainLookup\SearchResult results
 */
function metunicapi_CheckAvailability($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    // availability check parameters
    $searchTerm = $params['searchTerm'];
    $punyCodeSearchTerm = $params['punyCodeSearchTerm'];
    $tldsToInclude = $params['tldsToInclude'];
    $isIdnDomain = (bool) $params['isIdnDomain'];
    $premiumEnabled = (bool) $params['premiumEnabled'];

    try {
        $api = new ApiClient();
        $results = new ResultsList();
        foreach ($tldsToInclude as $tld) {
            $domain = $searchTerm . '.' . $tld;
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
        }
        return $results;
    } catch (\Exception $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * Domain Suggestion Settings.
 *
 * Defines the settings relating to domain suggestions (optional).
 * It follows the same convention as `getConfigArray`.
 *
 * @see https://developers.whmcs.com/domain-registrars/check-availability/
 *
 * @return array of Configuration Options
 */
function registrarmodule_DomainSuggestionOptions() {
    return array(
        'includeCCTlds' => array(
            'FriendlyName' => 'Include Country Level TLDs',
            'Type' => 'yesno',
            'Description' => 'Tick to enable',
        ),
    );
}

/**
 * Get Domain Suggestions.
 *
 * Provide domain suggestions based on the domain lookup term provided.
 *
 * @param array $params common module parameters
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @see \WHMCS\Domains\DomainLookup\SearchResult
 * @see \WHMCS\Domains\DomainLookup\ResultsList
 *
 * @throws Exception Upon domain suggestions check failure.
 *
 * @return \WHMCS\Domains\DomainLookup\ResultsList An ArrayObject based collection of \WHMCS\Domains\DomainLookup\SearchResult results
 */
function registrarmodule_GetDomainSuggestions($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $accountMode = $params['AccountMode'];
    $emailPreference = $params['EmailPreference'];

    // availability check parameters
    $searchTerm = $params['searchTerm'];
    $punyCodeSearchTerm = $params['punyCodeSearchTerm'];
    $tldsToInclude = $params['tldsToInclude'];
    $isIdnDomain = (bool) $params['isIdnDomain'];
    $premiumEnabled = (bool) $params['premiumEnabled'];
    $suggestionSettings = $params['suggestionSettings'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'searchTerm' => $searchTerm,
        'tldsToSearch' => $tldsToInclude,
        'includePremiumDomains' => $premiumEnabled,
        'includeCCTlds' => $suggestionSettings['includeCCTlds'],
    );

    try {
        $api = new ApiClient();
        $api->call('GetSuggestions', $postfields);

        $results = new ResultsList();
        foreach ($api->getFromResponse('domains') as $domain) {

            // Instantiate a new domain search result object
            $searchResult = new SearchResult($domain['sld'], $domain['tld']);

            // All domain suggestions should be available to register
            $searchResult->setStatus(SearchResult::STATUS_NOT_REGISTERED);

            // Used to weight results by relevance
            $searchResult->setScore($domain['score']);

            // Return premium information if applicable
            if ($domain['isPremiumName']) {
                $searchResult->setPremiumDomain(true);
                $searchResult->setPremiumCostPricing(
                    array(
                        'register' => $domain['premiumRegistrationPrice'],
                        'renew' => $domain['premiumRenewPrice'],
                        'CurrencyCode' => 'USD',
                    )
                );
            }

            // Append to the search results list
            $results->append($searchResult);
        }

        return $results;

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Get registrar lock status.
 *
 * Also known as Domain Lock or Transfer Lock status.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return string|array Lock status or error message
 */
function metunicapi_GetRegistrarLock($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);


    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    try {
        $api = new ApiClient();
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
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function metunicapi_SaveRegistrarLock($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $auth = array('username' => $userIdentifier, 'password' => $apiKey);

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // lock status
    $lockStatus = $params['lockenabled'];

    try {
        $api = new ApiClient();
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
 * Get DNS Records for DNS Host Record Management.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array DNS Host Records
 */
function registrarmodule_GetDNS($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $accountMode = $params['AccountMode'];
    $emailPreference = $params['EmailPreference'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
    );

    try {
        $api = new ApiClient();
        $api->call('GetDNSHostRecords', $postfields);

        $hostRecords = array();
        foreach ($api->getFromResponse('records') as $record) {
            $hostRecords[] = array(
                "hostname" => $record['name'], // eg. www
                "type" => $record['type'], // eg. A
                "address" => $record['address'], // eg. 10.0.0.1
                "priority" => $record['mxpref'], // eg. 10 (N/A for non-MX records)
            );
        }
        return $hostRecords;

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Update DNS Host Records.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function registrarmodule_SaveDNS($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $accountMode = $params['AccountMode'];
    $emailPreference = $params['EmailPreference'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // dns record parameters
    $dnsrecords = $params['dnsrecords'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'records' => $dnsrecords,
    );

    try {
        $api = new ApiClient();
        $api->call('GetDNSHostRecords', $postfields);

        return array(
            'success' => 'success',
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Enable/Disable ID Protection.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function registrarmodule_IDProtectToggle($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $accountMode = $params['AccountMode'];
    $emailPreference = $params['EmailPreference'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // id protection parameter
    $protectEnable = (bool) $params['protectenable'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
    );

    try {
        $api = new ApiClient();

        if ($protectEnable) {
            $api->call('EnableIDProtection', $postfields);
        } else {
            $api->call('DisableIDProtection', $postfields);
        }

        return array(
            'success' => 'success',
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Request EEP Code.
 *
 * Supports both displaying the EPP Code directly to a user or indicating
 * that the EPP Code will be emailed to the registrant.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 *
 */
function registrarmodule_GetEPPCode($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $accountMode = $params['AccountMode'];
    $emailPreference = $params['EmailPreference'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
    );

    try {
        $api = new ApiClient();
        $api->call('RequestEPPCode', $postfields);

        if ($api->getFromResponse('eppcode')) {
            // If EPP Code is returned, return it for display to the end user
            return array(
                'eppcode' => $api->getFromResponse('eppcode'),
            );
        } else {
            // If EPP Code is not returned, it was sent by email, return success
            return array(
                'success' => 'success',
            );
        }

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Release a Domain.
 *
 * Used to initiate a transfer out such as an IPSTAG change for .UK
 * domain names.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function registrarmodule_ReleaseDomain($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $accountMode = $params['AccountMode'];
    $emailPreference = $params['EmailPreference'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // transfer tag
    $transferTag = $params['transfertag'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'newtag' => $transferTag,
    );

    try {
        $api = new ApiClient();
        $api->call('ReleaseDomain', $postfields);

        return array(
            'success' => 'success',
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Delete Domain.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function registrarmodule_RequestDelete($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $accountMode = $params['AccountMode'];
    $emailPreference = $params['EmailPreference'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
    );

    try {
        $api = new ApiClient();
        $api->call('DeleteDomain', $postfields);

        return array(
            'success' => 'success',
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Register a Nameserver.
 *
 * Adds a child nameserver for the given domain name.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function registrarmodule_RegisterNameserver($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $accountMode = $params['AccountMode'];
    $emailPreference = $params['EmailPreference'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // nameserver parameters
    $nameserver = $params['nameserver'];
    $ipAddress = $params['ipaddress'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'nameserver' => $nameserver,
        'ip' => $ipAddress,
    );

    try {
        $api = new ApiClient();
        $api->call('RegisterNameserver', $postfields);

        return array(
            'success' => 'success',
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Modify a Nameserver.
 *
 * Modifies the IP of a child nameserver.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function registrarmodule_ModifyNameserver($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $accountMode = $params['AccountMode'];
    $emailPreference = $params['EmailPreference'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // nameserver parameters
    $nameserver = $params['nameserver'];
    $currentIpAddress = $params['currentipaddress'];
    $newIpAddress = $params['newipaddress'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'nameserver' => $nameserver,
        'currentip' => $currentIpAddress,
        'newip' => $newIpAddress,
    );

    try {
        $api = new ApiClient();
        $api->call('ModifyNameserver', $postfields);

        return array(
            'success' => 'success',
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Delete a Nameserver.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function registrarmodule_DeleteNameserver($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $accountMode = $params['AccountMode'];
    $emailPreference = $params['EmailPreference'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // nameserver parameters
    $nameserver = $params['nameserver'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'nameserver' => $nameserver,
    );

    try {
        $api = new ApiClient();
        $api->call('DeleteNameserver', $postfields);

        return array(
            'success' => 'success',
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Sync Domain Status & Expiration Date.
 *
 * Domain syncing is intended to ensure domain status and expiry date
 * changes made directly at the domain registrar are synced to WHMCS.
 * It is called periodically for a domain.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function registrarmodule_Sync($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $accountMode = $params['AccountMode'];
    $emailPreference = $params['EmailPreference'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
    );

    try {
        $api = new ApiClient();
        $api->call('GetDomainInfo', $postfields);

        return array(
            'expirydate' => $api->getFromResponse('expirydate'), // Format: YYYY-MM-DD
            'active' => (bool) $api->getFromResponse('active'), // Return true if the domain is active
            'transferredAway' => (bool) $api->getFromResponse('transferredaway'), // Return true if the domain is transferred out
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Incoming Domain Transfer Sync.
 *
 * Check status of incoming domain transfers and notify end-user upon
 * completion. This function is called daily for incoming domains.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function registrarmodule_TransferSync($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $accountMode = $params['AccountMode'];
    $emailPreference = $params['EmailPreference'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
    );

    try {
        $api = new ApiClient();
        $api->call('CheckDomainTransfer', $postfields);

        if ($api->getFromResponse('transfercomplete')) {
            return array(
                'completed' => true,
                'expirydate' => $api->getFromResponse('expirydate'), // Format: YYYY-MM-DD
            );
        } elseif ($api->getFromResponse('transferfailed')) {
            return array(
                'failed' => true,
                'reason' => $api->getFromResponse('failurereason'), // Reason for the transfer failure if available
            );
        } else {
            // No status change, return empty array
            return array();
        }

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Client Area Custom Button Array.
 *
 * Allows you to define additional actions your module supports.
 * In this example, we register a Push Domain action which triggers
 * the `registrarmodule_push` function when invoked.
 *
 * @return array
 */
function registrarmodule_ClientAreaCustomButtonArray()
{
    return array(
        'Push Domain' => 'push',
    );
}

/**
 * Client Area Allowed Functions.
 *
 * Only the functions defined within this function or the Client Area
 * Custom Button Array can be invoked by client level users.
 *
 * @return array
 */
function registrarmodule_ClientAreaAllowedFunctions()
{
    return array(
        'Push Domain' => 'push',
    );
}

/**
 * Example Custom Module Function: Push
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function registrarmodule_push($params)
{
    // user defined configuration values
    $userIdentifier = $params['APIUsername'];
    $apiKey = $params['APIKey'];
    $testMode = $params['TestMode'];
    $accountMode = $params['AccountMode'];
    $emailPreference = $params['EmailPreference'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // Perform custom action here...

    return 'Not implemented';
}

/**
 * Client Area Output.
 *
 * This function renders output to the domain details interface within
 * the client area. The return should be the HTML to be output.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return string HTML Output
 */
function registrarmodule_ClientArea($params)
{
    $output = '
        <div class="alert alert-info">
            Your custom HTML output goes here...
        </div>
    ';

    return $output;
}
