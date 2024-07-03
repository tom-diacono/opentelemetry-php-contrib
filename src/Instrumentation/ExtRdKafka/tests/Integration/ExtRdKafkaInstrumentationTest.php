<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\ExtRdKafka\tests\Integration;

use ArrayObject;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\Message;
use RdKafka\Producer;

class ExtRdKafkaInstrumentationTest extends TestCase
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

    public function test_consume_creates_new_span(): void
    {
        // Given
        $this->produceMessage('test');
        $this->assertCount(0, $this->storage);

        // When
        $this->consumeMessage();

        // Then
        $this->assertCount(1, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage->offsetGet(0);
        $this->assertEquals('Consumer: test', $span->getName());
    }

    public function test_context_propagated_on_consumption(): void
    {
        // Given
        $this->produceMessage('test', null, ['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01']);
        $this->assertCount(0, $this->storage);

        // When
        $this->consumeMessage();

        // Then
        $this->assertCount(1, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage->offsetGet(0);
        $this->assertEquals('Consumer: test', $span->getName());
        $this->assertEquals('0af7651916cd43dd8448eb211c80319c', $span->getContext()->getTraceId());
    }

    public function test_context_set_in_kafka_headers_on_message_production(): void
    {
        // Given
        $this->assertCount(0, $this->storage);
        $tracerProvider = Globals::tracerProvider();
        $tracer = $tracerProvider->getTracer('test');
        $span = $tracer->spanBuilder('test_span')->startSpan();
        $scope = $span->activate();

        $this->produceMessage('test');

        $scope->detach();
        $span->end();

        // When
        $message = $this->consumeMessage();
        $this->assertIsArray($message->headers);
        $this->assertArrayHasKey('traceparent', $message->headers);
        $this->assertEquals(
            sprintf('00-%s-%s-01', $span->getContext()->getTraceId(), $span->getContext()->getSpanId()),
            $message->headers['traceparent']
        );
    }

    private function produceMessage(string $message, ?string $key = null, array $headers = null): void
    {
        $conf = new Conf();
        $producer = new Producer($conf);
        $producer->addBrokers('kafka:9092');

        $topic = $producer->newTopic('test');

        $topic->producev(RD_KAFKA_PARTITION_UA, 0, $message, $key, $headers);
        $producer->poll(100);
        for ($flushRetries = 0; $flushRetries < 10; $flushRetries++) {
            $result = $producer->flush(10000);
            if (RD_KAFKA_RESP_ERR_NO_ERROR === $result) {
                break;
            }
        }
    }

    private function consumeMessage(): Message
    {
        $conf = new Conf();

        $conf->setRebalanceCb(function (KafkaConsumer $kafka, $err, array $partitions = null) {
            switch ($err) {
                case RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS:
                    $kafka->assign($partitions);

                    break;
                case RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS:
                    $kafka->assign(null);

                    break;
                default:
                    throw new \Exception($err);
            }
        });

        $conf->set('group.id', 'myConsumerGroup');
        $conf->set('metadata.broker.list', getenv('KAFKA_HOST') ?: 'kafka' . ':9092');
        $conf->set('auto.offset.reset', 'earliest');
        $conf->set('enable.partition.eof', 'true');

        $consumer = new KafkaConsumer($conf);

        $consumer->subscribe(['test']);

        while (true) {
            $message = $consumer->consume(50);
            switch ($message->err) {
                case RD_KAFKA_RESP_ERR_NO_ERROR:
                    $consumer->commit($message);

                    return $message;
                case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                case RD_KAFKA_RESP_ERR__TIMED_OUT:
                    break;
                default:
                    throw new \Exception($message->errstr(), $message->err);
            }
        }
    }
}
