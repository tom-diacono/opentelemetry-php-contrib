<?php

namespace MethodAttributes\Helper;

use OpenTelemetry\Contrib\Instrumentation\MethodAttributes\Attributes\WithSpan;

class InstrumentedWithAttributesClass
{
    public function __construct(
        private bool $shouldThrow = false,
    ) {
    }

    #[WithSpan]
    public function addNumbers($param1, $param2): int
    {
        if ($this->shouldThrow) {
            throw new \Exception('This is a test exception');
        }

        return $param1 + $param2;
    }
}
