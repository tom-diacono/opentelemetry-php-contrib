<?php

namespace OpenTelemetry\Contrib\Instrumentation\MethodAttributes\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class WithSpan
{
    public function __construct(
        private ?string $name = null,
        private ?string $kind = null,
    ) {
    }
}
