<?php

namespace Schwarzwaldgeier\WetterSocket;

use PHPUnit\Framework\TestCase;

class ExternalEndpointTest extends TestCase
{
    public function testSendToGeier(){
        $record = new Record("22:51:02, 08.02.22, TE21.26, DR1046.95, FE32.07, WS16.34, WD30.25, WC3.09, WV71.6,");

        echo $record;

        require_once "../src/endpoints.secret.php";
        $endpoint = new ExternalEndpoint(GEIER);

        $endpoint->getParamsFromRecord($record);
        print_r($endpoint->parameters);
        self::assertEquals(56, $endpoint->parameters['wd']);


        //TODO MOCK!
            /*
             *
        $endpoint->method="GET";
        $result = $endpoint->send();
        print_r($result);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('response', $result);
        $this->assertStringContainsString( "ThatWasGood", $result['response']);
        $this->assertEquals( 200, $result['status']);
            */
    }
}
