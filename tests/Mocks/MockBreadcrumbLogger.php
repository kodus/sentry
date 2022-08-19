<?php

namespace Tests\Mocks;

use Kodus\Sentry\Extensions\BreadcrumbLogger;

class MockBreadcrumbLogger extends BreadcrumbLogger
{
    public $time = 1540994720;

    protected function createTimestamp(): int
    {
        return $this->time;
    }
}
