<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\ExtSoap\tests\Integration;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\Tests\Instrumentation\ExtSoap\tests\MockSoapClient;
use PHPUnit\Framework\TestCase;
use Psalm\Issue\Trace;
use ReflectionClass;
use SoapClient;
use SoapFault;

class ExtSoapInstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;
    private TracerProvider $tracerProvider;
    private SoapClient $client;

    public function setUp(): void
    {
        $this->storage = new ArrayObject();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );

        $this->scope = Configurator::create()
            ->withTracerProvider($this->tracerProvider)
            ->withPropagator(TraceContextPropagator::getInstance())
            ->activate();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    /**
     * @dataProvider requestProvider
     * @throws SoapFault
     */
    public function test_send_request(string $method, string $uri, int $statusCode): void
    {
        $this->assertCount(0, $this->storage);

        $this->client = MockSoapClient::getFakeSoapClient('http://www.dneonline.com/calculator.asmx?wsdl', [
            'trace' => 1,
            'exception' => 1,
            'location' => $uri,
            'cache_wsdl' => WSDL_CACHE_NONE,
        ]);
        $this->client->__soapCall('SomeMethod', ['Arg1', 'Arg2']);

        $this->assertCount(1, $this->storage);

        /** @var ImmutableSpan $span */
        $span = $this->storage[0];

        $this->assertStringContainsString('SomeMethod', $span->getName());
        $this->assertTrue($span->getAttributes()->has(TraceAttributes::URL_FULL));
        $this->assertSame($uri, $span->getAttributes()->get(TraceAttributes::URL_FULL));
//        $this->assertTrue($span->getAttributes()->has(TraceAttributes::HTTP_REQUEST_METHOD));
//        $this->assertSame($method, $span->getAttributes()->get(TraceAttributes::HTTP_REQUEST_METHOD));
//        $this->assertTrue($span->getAttributes()->has(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
//        $this->assertSame($statusCode, $span->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
    }

    public function test_send_request_real_api(): void
    {
        $this->markTestSkipped('skip');
        $this->assertCount(0, $this->storage);

        $data = <<<XML

<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <Add xmlns="http://tempuri.org/">
      <intA>3</intA>
      <intB>7</intB>
    </Add>
  </soap:Body>
</soap:Envelope>
XML;

        try {
            $api->__soapCall('add', [new \SoapVar($data, XSD_ANYXML)]);
        } catch (SoapFault $e) {
            var_dump($e->getCode());
        }

//        $api->add(7, 3);

        $this->assertCount(1, $this->storage);

        /** @var ImmutableSpan $span */
        $span = $this->storage[0];
        $test = $span->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE);

        return;
    }

    public function requestProvider(): array
    {
        return [
            ['POST', 'http://example.com/foo', 200],
            ['POST', 'https://example.com/bar', 401],
        ];
    }
}
