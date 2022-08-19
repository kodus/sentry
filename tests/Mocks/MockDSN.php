<?php

namespace Tests\Mocks;

use Kodus\Sentry\Model\DSN;

class MockDSN extends DSN
{
    const MOCK_DSN = "https://0123456789abcdef0123456789abcdef@sentry.io/1234567";

    public $time = 1538738714;

    public function __construct()
    {
        parent::__construct(self::MOCK_DSN);
    }

    protected function getTime(): float
    {
        return $this->time;
    }
}
