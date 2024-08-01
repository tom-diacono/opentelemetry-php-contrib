<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\MethodAttributes;

use Composer\InstalledVersions;
use Exception;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use RuntimeException;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

class MethodAttributesInstrumentation
{
    public const NAME = 'method_attributes';

    /**
     * @throws RuntimeException
     */
    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.method_attributes',
            InstalledVersions::getVersion('open-telemetry/opentelemetry-auto-method-attributes'),
            'https://opentelemetry.io/schemas/1.25.0',
        );

        // Load all classes by iterating over the files in the project directory.
        $instrumentationDirectory = getenv('INSTRUMENTATION_DIRECTORY') ?: throw new RuntimeException('INSTRUMENTATION_DIRECTORY not set');

        $directory = new \RecursiveDirectoryIterator($instrumentationDirectory);
        $iterator = new \RecursiveIteratorIterator($directory);
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                require_once $file->getPathname();
            }
        }

        // Get all declared classes
        $classes = get_declared_classes();
        foreach ($classes as $class) {
            $reflectionClass = new \ReflectionClass($class);
            $methods = $reflectionClass->getMethods();
            foreach ($methods as $method) {
                $attributes = $method->getAttributes();
                foreach ($attributes as $attribute) {
                    if ($attribute->getName() === 'OpenTelemetry\Contrib\Instrumentation\MethodAttributes\Attributes\WithSpan') {
                        self::addMethodHooks($instrumentation, $class, $method->getName());
                    }
                }
            }
        }
    }

    private static function addMethodHooks(
        CachedInstrumentation $instrumentation,
        string $className,
        string $methodName,
    ) {
        hook(
            $className,
            $methodName,
            pre: static function (
                mixed $instantiatedClass,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno
            ) use ($instrumentation, $className, $methodName) : array {
                /** @var CachedInstrumentation $instrumentation */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder($className . '::' . $methodName)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
                ;

                $parent = Context::getCurrent();

                $span = $builder
                    ->setParent($parent)
                    ->startSpan();

                $context = $span->storeInContext($parent);
                Context::storage()->attach($context);
                return $params;
            },
            post: static function (
                mixed $instantiatedClass,
                array $params,
                $returnValue,
                ?Throwable $exception
            ) {
                $scope = Context::storage()->scope();
                $span = Span::fromContext($scope->context());

                if ($exception !== null) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }

                $scope->detach();
                $span->end();

                return $returnValue;
            }
        );
    }
}
