<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\MethodAttributes\MethodAttributesInstrumentation;
use OpenTelemetry\SDK\Sdk;

if (class_exists(Sdk::class) && Sdk::isInstrumentationDisabled(MethodAttributesInstrumentation::NAME) === true) {
    return;
}

if (extension_loaded('opentelemetry') === false) {
    trigger_error('The opentelemetry extension must be loaded in order to autoload the OpenTelemetry method-attributes auto-instrumentation', E_USER_WARNING);

    return;
}

if (!extension_loaded('rdkafka')) {
    return;
}

MethodAttributesInstrumentation::register();
