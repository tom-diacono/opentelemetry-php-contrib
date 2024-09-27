<?php

namespace OpenTelemetry\Tests\Instrumentation\ExtSoap\tests;

use SoapClient;

class MockSoapClient
{
    public static function getFakeSoapClient(?string $wsdl, array $options = [])
    {
        return new class($wsdl, $options) extends SoapClient {
            private string $location;

            public function __construct(?string $wsdl, array $options = [])
            {
                parent::__construct($wsdl, $options);
                $this->location = $options['location'] ?? 'localhost';
            }

            public function __soapCall(
                $name,
                array $args,
                $options = null,
                $inputHeaders = null,
                &$outputHeaders = null
            ) {
                return 'SomeData';
            }

            public function __getLastResponseHeaders(): ?string
            {
                return 'HTTP/1.1 403 Forbidden';
            }
        };
    }
}
