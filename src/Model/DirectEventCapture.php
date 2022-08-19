<?php

namespace Kodus\Sentry\Model;

use function json_decode;
use function json_encode;
use RuntimeException;

/**
 * This class implements capture of {@see Event} instances directly to the
 * Sentry back-end via an HTTP request.
 */
class DirectEventCapture implements EventCapture
{
    /**
     * @var string|null
     */
    private $proxy;

    /**
     * @var DSN
     */
    private $dsn;

    /**
     * @param DSN         $dsn
     * @param string|null $proxy optional proxy server for outgoing HTTP requests (e.g. "tcp://proxy.example.com:5100")
     */
    public function __construct(DSN $dsn, ?string $proxy = null)
    {
        $this->dsn = $dsn;
        $this->proxy = $proxy;
    }

    public function captureEvent(Event $event): void
    {
        $body = json_encode($event, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        $headers = [
            "Accept: application/json",
            "Content-Type: application/json",
            DSN::AUTH_HEADER_NAME . ": " . $this->dsn->getAuthHeader(),
        ];

        try {
            $this->fetch("POST", $this->dsn->getURL(), $body, $headers);
        } catch (RuntimeException $error) {
            error_log("SentryClient: unable to access Sentry service [{$error->getMessage()}]");
        }
    }

    /**
     * Perform an HTTP request and return the response body.
     *
     * The request must return a 200 status-code.
     *
     * @param string $method HTTP method ("GET", "POST", etc.)
     * @param string $url
     * @param string $body
     * @param array  $headers
     *
     * @return string response body
     *
     * @throws RuntimeException if unable to open the resource
     * @throws RuntimeException for unexpected (non-200) response code
     */
    protected function fetch(string $method, string $url, string $body, array $headers = []): string
    {
        $context = stream_context_create([
            "http" => [
                // http://docs.php.net/manual/en/context.http.php
                "method"        => $method,
                "header"        => implode("\r\n", $headers),
                "content"       => $body,
                "ignore_errors" => true,
                "proxy"         => $this->proxy,
            ],
        ]);

        $stream = @fopen($url, "r", false, $context);

        if ($stream === false) {
            throw new RuntimeException("unable to open resource: {$method} {$url}");
        }

        $response = stream_get_contents($stream);

        $headers = stream_get_meta_data($stream)['wrapper_data'];

        $status_line = $headers[0];

        fclose($stream);

        preg_match('{HTTP\/\S*\s(\d{3})}', $status_line, $match);

        $status = $match[1];

        if ($status !== "200") {
            throw new RuntimeException("unexpected response status: {$status_line} ({$method} {$url})");
        }

        return $response;
    }
}
