<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\ExtSoap;

use Composer\InstalledVersions;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Psr18\HeadersPropagator;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Http\Message\RequestInterface;
use SoapClient;

use SoapFault;
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
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno
            ) use ($instrumentation) {
//                $propagator = Globals::propagator();
                $parentContext = Context::getCurrent();

                var_dump($params);

                /** @psalm-suppress ArgumentTypeCoercion */
                $spanBuilder = $instrumentation
                    ->tracer()
                    ->spanBuilder(sprintf('%s', $params[0] ?? null))
                    ->setParent($parentContext)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    // TODO- Work out below, it'll work in WSDL mode
                    ->setAttribute(TraceAttributes::URL_FULL, (string) $client->_get('location'))
//                    ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
//                    ->setAttribute(TraceAttributes::SERVER_ADDRESS, $request->getUri()->getHost())
//                    ->setAttribute(TraceAttributes::SERVER_PORT, $request->getUri()->getPort())
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
                    ->setAttribute('body', $params[1] ?? null)
                ;

//                foreach ($propagator->fields() as $field) {
//                    $request = $request->withoutHeader($field);
//                }
//                //@todo could we use SDK Configuration to retrieve this, and move into a key such as OTEL_PHP_xxx?
//                foreach ((array) (get_cfg_var('otel.instrumentation.http.request_headers') ?: []) as $header) {
//                    if ($request->hasHeader($header)) {
//                        $spanBuilder->setAttribute(
//                            sprintf('http.request.header.%s', strtolower($header)),
//                            $request->getHeader($header)
//                        );
//                    }
//                }

                $span = $spanBuilder->startSpan();
                $context = $span->storeInContext($parentContext);
//                $propagator->inject($request, HeadersPropagator::instance(), $context);

                Context::storage()->attach($context);

                return $params;
            },
            post: function (
                SoapClient $client,
                array $params,
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

                if ($responseCode >= 400 && $responseCode < 600) {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }

                if ($exception) {
                    $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            }
        );
    }

    private static function extractHttpResponseFromResponseHeaders(SoapClient $client): ?int
    {
        $headers = $client->__getLastResponseHeaders();

        // Extract the HTTP status code from the headers
        if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $headers, $matches)) {
            return (int) $matches[1];
        } else {
            return null;
        }
    }
}
