<?php

namespace Kodus\Sentry\Extensions;

use Kodus\Sentry\Model\Breadcrumb;
use Kodus\Sentry\Model\Event;
use Kodus\Sentry\Model\Level;
use Kodus\Sentry\SentryClientExtension;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * This PSR-3 Logger delegates log-entries to a {@see SentryClient} as Breadcrumbs.
 *
 * Note that long-running apps (if they reuse the client/extension for multiple requests)
 * needs to call the {@see clear()} method at the end of a successful web-request, since
 * log-entries will otherwise accumulate indefinitely.
 *
 * @see LoggerInterface
 */
class BreadcrumbLogger extends AbstractLogger implements SentryClientExtension
{
    /**
     * @var string[]
     */
    private $log_levels;

    /**
     * @var Breadcrumb[] list of Breadcrumbs being collected for the next Event
     */
    private $breadcrumbs = [];

    /**
     * @param string[] $log_levels map where PSR-5 LogLevel => Sentry Level (optional)
     *
     * @see LogLevel
     * @see Level
     */
    public function __construct(
        array $log_levels = [
            LogLevel::EMERGENCY => Level::FATAL,
            LogLevel::ALERT     => Level::FATAL,
            LogLevel::CRITICAL  => Level::FATAL,
            LogLevel::ERROR     => Level::ERROR,
            LogLevel::WARNING   => Level::WARNING,
            LogLevel::NOTICE    => Level::INFO,
            LogLevel::INFO      => Level::INFO,
            LogLevel::DEBUG     => Level::DEBUG,
        ]
    ) {
        $this->log_levels = $log_levels;
    }

    /**
     * @inheritdoc
     */
    public function log($level, $message, array $context = [])
    {
        $this->breadcrumbs[] = new Breadcrumb(
            $this->createTimestamp(),
            $this->log_levels[$level] ?? $level,
            "[{$level}] {$message}",
            $context
        );
    }

    /**
     * Clears any Breadcrumbs collected via {@see log()}.
     */
    public function clear(): void
    {
        $this->breadcrumbs = [];
    }

    /**
     * @internal this is called internally by the client at capture
     */
    public function apply(Event $event, Throwable $exception, ?ServerRequestInterface $request): void
    {
        $event->breadcrumbs = array_merge(
            $event->breadcrumbs,
            $this->breadcrumbs
        );

        $this->clear();
    }

    /**
     * @return int current time
     */
    protected function createTimestamp(): int
    {
        return time();
    }
}
