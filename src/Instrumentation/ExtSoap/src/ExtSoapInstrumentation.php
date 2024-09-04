<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\ExtSoap;

use Composer\InstalledVersions;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;

use PHPStan\Reflection\Php\Soap\SoapClientMethodReflection;

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
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno
            ) {
            },
            post: function (
                SoapClient $class,
                array $params,
                $returnValue,
                ?Throwable $exception
            ) {
            }
        );
    }
}
