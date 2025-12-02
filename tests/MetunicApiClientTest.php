<?php

use WHMCS\Module\Registrar\Metunicapi\ApiClient;

class MetunicApiClientTest extends PHPUnit_Framework_TestCase
{
    public function testPhoneFormatting()
    {
        $client = new ApiClient();
        $this->assertEquals('+905551234567', $client->formatPhoneE164('+90 555 123 45 67'));
        $this->assertEquals('+15551234567', $client->formatPhoneE164('1-555-123-4567'));
    }
}

