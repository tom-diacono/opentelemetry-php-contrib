<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\ExtSoap;

use Composer\InstalledVersions;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\TraceAttributeValues;
use ReflectionClass;
use ReflectionException;
use SoapClient;
use Throwable;
use function OpenTelemetry\Instrumentation\hook;

final class ExtSoapInstrumentation
{
    public const NAME = 'ext_soap';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.ext_soap',
            InstalledVersions::getVersion('open-telemetry/opentelemetry-auto-ext-soap'),
            'https://opentelemetry.io/schemas/1.25.0',
        );

        hook(
            SoapClient::class,
            '__soapCall',
            pre: function (
                SoapClient $client,
                array      $params,
                string     $class,
                string     $function,
                ?string    $filename,
                ?int       $lineno
            ) use ($instrumentation) {
                $parentContext = Context::getCurrent();

                /** @psalm-suppress ArgumentTypeCoercion */
                $spanBuilder = $instrumentation
                    ->tracer()
                    ->spanBuilder(sprintf('%s', $params[0] ?? null))
                    ->setParent($parentContext)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::URL_FULL, self::extractPrivateVar($client, 'location'))
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, TraceAttributeValues::HTTP_REQUEST_METHOD_POST)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);

                $span = $spanBuilder->startSpan();
                $context = $span->storeInContext($parentContext);
                Context::storage()->attach($context);

                return $params;
            },
            post: function (
                SoapClient $client,
                array      $params,
                           $returnValue,
                ?Throwable $exception
            ) {
                $scope = Context::storage()->scope();
                $scope?->detach();

                if (!$scope || $scope->context() === Context::getCurrent()) {
                    return;
                }

                $span = Span::fromContext($scope->context());

                $responseCode = self::extractHttpResponseFromResponseHeaders($client);
                $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $responseCode);

                if ($exception) {
                    $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                } elseif ($responseCode >= 400 && $responseCode < 600) {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }

                $span->end();
            }
        );
    }

    private static function extractHttpResponseFromResponseHeaders(SoapClient $client): ?int
    {
        $headers = $client->__getLastResponseHeaders();

        if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $headers, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /** @throws ReflectionException */
    private static function extractPrivateVar(SoapClient $client, string $name)
    {
        $reflection = new ReflectionClass($client);
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $value = $property->getValue($client);
        $property->setAccessible(false);
        return $value;
    }
}
