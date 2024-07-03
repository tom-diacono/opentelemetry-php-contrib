<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\ExtRdKafka;

use Composer\InstalledVersions;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;

use OpenTelemetry\SemConv\TraceAttributes;

use RdKafka\KafkaConsumer;
use RdKafka\Message;
use RdKafka\ProducerTopic;

use Throwable;

class ExtRdKafkaInstrumentation
{
    public const NAME = 'ext_rdkafka';
    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.ext_rdkafka',
            InstalledVersions::getVersion('open-telemetry/opentelemetry-auto-ext-rdkafka'),
            TraceAttributes::SCHEMA_URL,
        );

        // Start root span and propagate parent if it exists in headers, for each message consumed
        self::addConsumeHooks($instrumentation);
        // End root span on offset commit
        self::addCommitHooks('commit');
        self::addCommitHooks('commitAsync');
        // Context propagation for outbound messages
        self::addProductionHooks();
    }

    private static function addCommitHooks($functionName)
    {
        hook(
            KafkaConsumer::class,
            $functionName,
            null,
            static function () {
                $scope = Context::storage()->scope();
                if ($scope === null) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                $span->end();
            }
        );
    }

    private static function addProductionHooks()
    {
        hook(
            ProducerTopic::class,
            'producev',
            static function (
                ProducerTopic $exchange,
                array $params
            ): array {
                // Headers are the 5th argument for the producev function
                $carrier = [];
                TraceContextPropagator::getInstance()->inject($carrier);
                $propagator = Globals::propagator();
                $propagator->inject($carrier);

                if (array_key_exists('traceparent', $carrier)) {
                    $params[4]['traceparent'] = $carrier['traceparent'];
                }

                return $params;
            }
        );
    }

    private static function addConsumeHooks($instrumentation)
    {
        hook(
            KafkaConsumer::class,
            'consume',
            null,
            static function (
                ?KafkaConsumer $exchange,
                array $params,
                ?Message $message,
                ?Throwable $exception
            ) use ($instrumentation) : void {
                // This is to ensure that there is data. Packages periodically poll this method in order to
                // determine if there is a message there. If there is not, we don't want to create a span.
                if (!$message instanceof Message || $message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                    return;
                }

                $builder = $instrumentation
                    ->tracer()
                    // @phan-suppress-next-line PhanTypeSuspiciousStringExpression - Doesn't seem to know this has to be a string
                    ->spanBuilder('Consumer: ' . $message->topic_name)
                    ->setSpanKind(SpanKind::KIND_CONSUMER)
                    ->setAttribute(TraceAttributes::MESSAGING_SYSTEM, 'kafka')
                    ->setAttribute(TraceAttributes::MESSAGING_OPERATION, 'consume')
                ;

                $parent = Context::getCurrent();
                $spanBuilder = $builder
                    ->setParent($parent);

                if (is_array($message->headers) &&  array_key_exists('traceparent', $message->headers)) {
                    $propagator = TraceContextPropagator::getInstance();
                    $otelContext = $propagator->extract(['traceparent' => $message->headers['traceparent']]);
                    $spanBuilder->setParent($otelContext);
                }

                $span = $spanBuilder->startSpan();

                $context = $span->storeInContext($parent);

                Context::storage()->attach($context);
            }
        );
    }
}
