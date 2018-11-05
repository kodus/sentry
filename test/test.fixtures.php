<?php

use Kodus\Sentry\Extensions\BreadcrumbLogger;
use Kodus\Sentry\Extensions\ClientIPDetector;
use Kodus\Sentry\Extensions\ClientSniffer;
use Kodus\Sentry\Extensions\EnvironmentReporter;
use Kodus\Sentry\Extensions\ExceptionReporter;
use Kodus\Sentry\Extensions\RequestReporter;
use Kodus\Sentry\Model\Event;
use Kodus\Sentry\SentryClient;

/**
 * This provides a fixture for stack-trace test-cases
 */
class TraceFixture
{
    public function outer($arg)
    {
        try {
            $this->inner($arg);
        } catch (Exception $inner) {
            throw new Exception("from outer: {$arg}", 0, $inner);
        }
    }

    protected function inner($arg)
    {
        $closure = function () use ($arg) {
            throw new Exception("from inner: {$arg}");
        };

        $closure();
    }
}

function exception_with($arg): Exception {
    $fixture = new TraceFixture();

    try {
        $fixture->outer($arg);
    } catch (Exception $exception) {
        return $exception;
    }
}

class ClassFixture
{
    public function instanceMethod()
    {
        // nothing here.
    }

    public static function staticMethod()
    {
        // nothing here.
    }
}

class InvokableClassFixture
{
    public function __invoke()
    {
        // nothing here.
    }
}

function empty_closure() {
    return function () {};
}

/**
 * This model represents an HTTP Request for testing purposes
 */
class Request
{
    /**
     * @var string
     */
    public $body;

    /**
     * @var string[]
     */
    public $headers;

    /**
     * @param string   $body
     * @param string[] $headers
     */
    public function __construct(string $body, array $headers)
    {
        $this->body = $body;
        $this->headers = $headers;
    }
}

class MockSentryClient extends SentryClient
{
    const MOCK_EVENT_ID = "a1f1cddefbd54085822f50ef14c7c9a8";

    const MOCK_DSN = "https://0123456789abcdef0123456789abcdef@sentry.io/1234567";

    public $time = 1538738714;

    /**
     * @var BreadcrumbLogger
     */
    public $logger;

    public function __construct(?array $extensions = null)
    {
        $this->logger = new BreadcrumbLogger();

        parent::__construct(
            self::MOCK_DSN,
            $extensions ?: [
                new EnvironmentReporter(),
                new RequestReporter(),
                new ExceptionReporter(__DIR__),
                new ClientSniffer(),
                new ClientIPDetector(),
            ]
        );
    }

    /**
     * @var Request[]
     */
    public $requests = [];

    protected function createTimestamp(): int
    {
        return $this->time;
    }

    protected function createEventID(): string
    {
        return self::MOCK_EVENT_ID;
    }

    protected function fetch(string $method, string $url, string $body, array $headers = []): string
    {
        $this->requests[] = new Request($body, $headers);

        return "";
    }

    public function testFetch(string $method, string $url, string $body, array $headers): string
    {
        return parent::fetch($method, $url, $body, $headers);
    }
}

class MockExceptionReporter extends ExceptionReporter
{
    public function testFormat($value): string
    {
        return $this->formatValue($value);
    }
}

class MockBreadcrumbLogger extends BreadcrumbLogger
{
    public $time = 1540994720;

    protected function createTimestamp(): int
    {
        return $this->time;
    }
}
