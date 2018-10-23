<?php

namespace Kodus\Sentry;

use Kodus\Sentry\Model\Level;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * This PSR-3 Logger delegates log-entries to a {@see SentryClient} as Breadcrumbs.
 *
 * @see LoggerInterface
 */
class BreadcrumbLogger extends AbstractLogger
{
    /**
     * @var SentryClient
     */
    private $client;

    /**
     * @var string[]
     */
    private $log_levels;

    /**
     * @param SentryClient  $client     Sentry to delegate to
     * @param string[]|null $log_levels map where PSR-5 LogLevel => Sentry Level (optional)
     *
     * @see LogLevel
     * @see Level
     */
    public function __construct(SentryClient $client, ?array $log_levels = null)
    {
        $this->client = $client;

        $this->log_levels = $log_levels ?: [
            LogLevel::EMERGENCY => Level::FATAL,
            LogLevel::ALERT     => Level::FATAL,
            LogLevel::CRITICAL  => Level::FATAL,
            LogLevel::ERROR     => Level::ERROR,
            LogLevel::WARNING   => Level::WARNING,
            LogLevel::NOTICE    => Level::INFO,
            LogLevel::INFO      => Level::INFO,
            LogLevel::DEBUG     => Level::DEBUG,
        ];
    }

    /**
     * @inheritdoc
     */
    public function log($level, $message, array $context = [])
    {
        $this->client->addBreadcrumb(
            "[{$level}] {$message}",
            $this->log_levels[$level] ?? $level,
            $context
        );
    }
}
