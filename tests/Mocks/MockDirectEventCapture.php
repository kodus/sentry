<?php

namespace Tests\Mocks;

use Kodus\Sentry\Model\DirectEventCapture;
use Kodus\Sentry\Model\DSN;

class MockDirectEventCapture extends DirectEventCapture
{
    /**
     * @var MockRequest[]
     */
    public $requests = [];

    public function __construct(?DSN $dsn = null, ?string $proxy = null)
    {
        parent::__construct($dsn ?: new MockDSN(), $proxy);
    }

    public function testFetch(string $method, string $url, string $body, array $headers): string
    {
        return parent::fetch($method, $url, $body, $headers);
    }

    protected function fetch(string $method, string $url, string $body, array $headers = []): string
    {
        $this->requests[] = new MockRequest($body, $headers);

        return "";
    }
}
