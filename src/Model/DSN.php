<?php

namespace Kodus\Sentry\Model;

use Kodus\Sentry\SentryClient;

/**
 * This model represents a Sentry DSN string
 */
class DSN
{
    /**
     * @var string Sentry authorization header-name
     *
     * @see getAuthHeader()
     */
    const AUTH_HEADER_NAME = "X-Sentry-Auth";

    /**
     * @var string Sentry API endpoint
     */
    private $url;

    /**
     * @var string X-Sentry authentication header template
     */
    private $auth_header;

    /**
     * @var string
     */
    private $dsn;

    /**
     * @param string $dsn Sentry DSN string
     */
    public function __construct(string $dsn)
    {
        $this->dsn = $dsn;

        $url = parse_url($dsn);

        $auth_header = implode(
            ", ",
            [
                "Sentry sentry_version=7",
                "sentry_timestamp=%s",
                "sentry_key={$url['user']}",
                "sentry_client=kodus-sentry/" . SentryClient::VERSION,
            ]
        );

        $this->auth_header = $auth_header;

        $this->url = "{$url['scheme']}://{$url['host']}/api{$url['path']}/store/";
    }

    /**
     * @return string
     */
    public function getURL(): string
    {
        return $this->url;
    }

    /**
     * @return string authorization header-value
     *
     * @see DSN::AUTH_HEADER_NAME
     */
    public function getAuthHeader(): string
    {
        return sprintf($this->auth_header, $this->getTime());
    }

    /**
     * @return string
     */
    public function getDSN(): string
    {
        return $this->dsn;
    }

    /**
     * @internal
     *
     * @return float
     */
    protected function getTime(): float
    {
        return microtime(true);
    }
}
