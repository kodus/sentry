<?php

namespace Kodus\Sentry;

use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class SentryClient
{
    /**
     * @var string Sentry API endpoint
     */
    private $url;

    /**
     * @var string[] map where PHP error-level => Sentry error-level
     *
     * @link http://php.net/manual/en/errorfunc.constants.php
     *
     * @link https://docs.sentry.io/clientdev/attributes/#optional-attributes
     */
    public $error_levels = [
        E_DEPRECATED        => EventLevel::WARNING,
        E_USER_DEPRECATED   => EventLevel::WARNING,
        E_WARNING           => EventLevel::WARNING,
        E_USER_WARNING      => EventLevel::WARNING,
        E_RECOVERABLE_ERROR => EventLevel::WARNING,
        E_ERROR             => EventLevel::FATAL,
        E_PARSE             => EventLevel::FATAL,
        E_CORE_ERROR        => EventLevel::FATAL,
        E_CORE_WARNING      => EventLevel::FATAL,
        E_COMPILE_ERROR     => EventLevel::FATAL,
        E_COMPILE_WARNING   => EventLevel::FATAL,
        E_USER_ERROR        => EventLevel::ERROR,
        E_NOTICE            => EventLevel::INFO,
        E_USER_NOTICE       => EventLevel::INFO,
        E_STRICT            => EventLevel::INFO,
    ];

    /**
     * @var HttpClient
     */
    private $http;

    /**
     * @var string X-Sentry authentication header
     */
    private $auth_header;

    /**
     * @var string
     */
    private $dsn;

    /**
     * @var OSContext
     */
    private $os;

    /**
     * @var RuntimeContext
     */
    private $runtime;

    /**
     * @param string          $dsn  Sentry DSN
     * @param HttpClient|null $http optional HTTP client (usually omitted)
     */
    public function __construct(string $dsn, HttpClient $http = null)
    {
        $this->dsn = $dsn;
        $this->http = $http ?: new HttpClient();

        $url = parse_url($this->dsn);

        $auth_tokens = implode(
            ", ",
            [
                "Sentry sentry_version=7",
                "sentry_timestamp=%s",
                "sentry_key={$url['user']}",
                "sentry_client=kodus-sentry/1.0",
            ]
        );

        $this->auth_header = "X-Sentry-Auth: " . $auth_tokens;

        $this->url = "{$url['scheme']}://{$url['host']}/api{$url['path']}/store/";

        $this->os = new OSContext();
        $this->runtime = new RuntimeContext();
    }

    /**
     * @param Throwable                   $exception the Exception to be logged
     * @param ServerRequestInterface|null $request   the PSR-7 Request (if applicable)
     *
     * @return string captured Event ID
     */
    public function captureException(Throwable $exception, ?ServerRequestInterface $request = null): string
    {
        $timestamp = $this->createTimestamp();

        $event_id = $this->createEventID();

        $event = new Event($event_id, $timestamp, $exception->getMessage());

        $event->addContext($this->os);

        $event->addContext($this->runtime);

        $event->addTag("server_name", php_uname('n'));

        if ($request) {
            $this->addRequestDetails($event, $request);
        }

        $body = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            "Accept: application/json",
            "Content-Type: application/json",
            $this->createAuthHeader($timestamp),
        ];

        $response = $this->http->fetch("POST", $this->url, $body, $headers);

        return $response;
    }

    protected function createTimestamp(): int
    {
        return time();
    }

    /**
     * @return string UUID v4 without the "-" separators (as required by Sentry)
     */
    protected function createEventID(): string
    {
        $bytes = unpack('C*', random_bytes(16));

        return sprintf(
            '%02x%02x%02x%02x%02x%02x%02x%02x%02x%02x%02x%02x%02x%02x%02x%02x',
            $bytes[1], $bytes[2], $bytes[3], $bytes[4],
            $bytes[5], $bytes[6],
            $bytes[7] & 0x0f | 0x40, $bytes[8],
            $bytes[9] & 0x3f | 0x80, $bytes[10],
            $bytes[11], $bytes[12], $bytes[13], $bytes[14], $bytes[15], $bytes[16]
        );
    }

    private function createAuthHeader(int $timestamp)
    {
        return sprintf($this->auth_header, $timestamp);
    }

    private function addRequestDetails(Event $event, ServerRequestInterface $request)
    {
        // $event->addTag("site", $request->getUri()->getHost());

        $event->addTag("site", $request->getUri()->getHost());

        $event->request = new Request($request->getUri()->__toString(), $request->getMethod());

        $event->request->query_string = $request->getUri()->getQuery();

        $event->request->cookies = $request->getCookieParams();

        $headers = [];

        foreach (array_keys($request->getHeaders()) as $name) {
            $headers[$name] = $request->getHeaderLine($name);
        }

        $event->request->headers = $headers;

        // TODO data?
        // TODO env?
    }
}
