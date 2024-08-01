<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\MethodAttributes\tests\Integration;

use ArrayObject;
use MethodAttributes\Helper\InstrumentedWithAttributesClass;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

class MethodAttributesInstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;
    private TracerProvider $tracerProvider;

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
            ->withPropagator(new TraceContextPropagator())
            ->activate();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_calling_method_creates_span()
    {
        $this->assertCount(0, $this->storage);

        $instrumentedClass = new InstrumentedWithAttributesClass();

        $instrumentedClass->addNumbers(1, 2);

        $this->assertCount(1, $this->storage);
    }

    public function test_should_error_span_on_exception()
    {
        $this->assertCount(0, $this->storage);

        $instrumentedClass = new InstrumentedWithAttributesClass(true);

        try {
            $instrumentedClass->addNumbers(1, 2);
        } catch (\Exception $e) {
            $this->assertCount(1, $this->storage);
        }

        $this->assertEquals(StatusCode::STATUS_ERROR, $this->storage[0]->getStatus()->getCode());
    }
}
