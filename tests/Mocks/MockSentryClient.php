<?php

namespace Tests\Mocks;

use Kodus\Sentry\Extensions\BreadcrumbLogger;
use Kodus\Sentry\Extensions\ClientIPDetector;
use Kodus\Sentry\Extensions\ClientSniffer;
use Kodus\Sentry\Extensions\EnvironmentReporter;
use Kodus\Sentry\Extensions\ExceptionReporter;
use Kodus\Sentry\Extensions\RequestReporter;
use Kodus\Sentry\Model\EventCapture;
use Kodus\Sentry\SentryClient;

class MockSentryClient extends SentryClient
{
    const MOCK_EVENT_ID = "a1f1cddefbd54085822f50ef14c7c9a8";

    /**
     * @var BreadcrumbLogger
     */
    public $logger;

    /**
     * @var int timestamp
     */
    public $time = 1538738714;

    public function __construct(EventCapture $capture, ?array $extensions = null, ?array $filters = [])
    {
        $this->logger = new BreadcrumbLogger();

        parent::__construct(
            $capture,
            $extensions ?: [
                new EnvironmentReporter(),
                new RequestReporter(),
                new ExceptionReporter(__DIR__, 200, $filters),
                new ClientSniffer(),
                new ClientIPDetector(),
            ]
        );
    }

    protected function createTimestamp(): int
    {
        return $this->time;
    }

    protected function createEventID(): string
    {
        return self::MOCK_EVENT_ID;
    }
}
