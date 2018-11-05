<?php

namespace Kodus\Sentry;

use Kodus\Sentry\Model\Breadcrumb;
use Kodus\Sentry\Model\Event;
use Kodus\Sentry\Model\Level;
use Kodus\Sentry\Model\UserInfo;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

// TODO grouping / fingerprints https://docs.sentry.io/learn/rollups/?platform=node#custom-grouping

class SentryClient
{
    /**
     * @string version of this client package
     */
    const VERSION = "1.0.0";

    /**
     * @var string|null optional proxy server for outgoing HTTP requests (e.g. "tcp://proxy.example.com:5100")
     */
    public $proxy;

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
     * @var SentryClientExtension[]
     */
    private $extensions;

    /**
     * @param string                  $dsn        Sentry DSN
     * @param SentryClientExtension[] $extensions list of Client Extensions to use
     */
    public function __construct(string $dsn, array $extensions = [])
    {
        $this->dsn = $dsn;

        $this->extensions = $extensions;

        $url = parse_url($this->dsn);

        $auth_tokens = implode(
            ", ",
            [
                "Sentry sentry_version=7",
                "sentry_timestamp=%s",
                "sentry_key={$url['user']}",
                "sentry_client=kodus-sentry/" . self::VERSION,
            ]
        );

        $this->auth_header = "X-Sentry-Auth: " . $auth_tokens;

        $this->url = "{$url['scheme']}://{$url['host']}/api{$url['path']}/store/";
    }

    /**
     * Create and capture details about a given {@see Throwable} and (optionally) an
     * associated {@see ServerRequestInterface}.
     *
     * @param Throwable                   $exception the Exception to be logged
     * @param ServerRequestInterface|null $request   the related PSR-7 Request (if applicable)
     */
    public function captureException(Throwable $exception, ?ServerRequestInterface $request = null): void
    {
        $event = $this->createEvent($exception, $request);

        $this->captureEvent($event);
    }

    /**
     * Create an {@see Event} instance with details about a given {@see Throwable} and
     * (optionally) an associated {@see ServerRequestInterface}.
     *
     * @param Throwable                   $exception the Exception to be logged
     * @param ServerRequestInterface|null $request   the related PSR-7 Request (if applicable)
     *
     * @return Event
     */
    protected function createEvent(Throwable $exception, ?ServerRequestInterface $request = null): Event
    {
        $event = new Event(
            $this->createEventID(),
            $this->createTimestamp(),
            $exception->getMessage(),
            new UserInfo()
        );

        // NOTE: the `transaction` field is actually not intended for the *source* of the error, but for
        //       something that describes the command that resulted in the error - something application
        //       dependent, like the web-route or console-command that triggered the problem. Since those
        //       things can't be established from here, and since we want something meaningful to display
        //       in the title of the Sentry error-page, this is the best we can do for now.

        $event->transaction = $exception->getFile() . "#" . $exception->getLine();

        foreach($this->extensions as $extension) {
            $extension->apply($event, $exception, $request);
        }

        return $event;
    }

    /**
     * Capture (HTTP `POST`) a given {@see Event} to Sentry.
     *
     * @param Event $event
     */
    protected function captureEvent(Event $event): void
    {
        $body = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            "Accept: application/json",
            "Content-Type: application/json",
            sprintf($this->auth_header, $event->timestamp),
        ];

        try {
            $response = $this->fetch("POST", $this->url, $body, $headers);
        } catch (RuntimeException $error) {
            error_log("SentryClient: unable to access Sentry service [{$error->getMessage()}]");

            return; // NOTE: fail silently
        }

        $data = json_decode($response, true);

        $event->event_id = $data["id"];
    }

    /**
     * @return int current time
     */
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
