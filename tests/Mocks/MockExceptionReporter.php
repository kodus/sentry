<?php

namespace Tests\Mocks;

use Kodus\Sentry\Extensions\ExceptionReporter;

class MockExceptionReporter extends ExceptionReporter
{
    public function testFormat($value): string
    {
        return $this->formatValue($value);
    }
}
