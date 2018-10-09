<?php

namespace Kodus\Sentry;

use Closure;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use RuntimeException;
use SplFileObject;
use Throwable;

class SentryClient
{
    /**
     * @var string[] map where PHP error-level => Sentry Event error-level
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
     * @var OSContext
     */
    private $os;

    /**
     * @var RuntimeContext
     */
    private $runtime;

    /**
     * @param string $dsn Sentry DSN
     */
    public function __construct(string $dsn)
    {
        $this->dsn = $dsn;

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

        $this->runtime = $this->createRuntimeContext();

        $this->os = $this->createOSContext();
    }

    /**
     * Capture details about a given {@see Throwable} and (optionally) an associated {@see ServerRequestInterface}.
     *
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

        $event->exception = $this->createExceptionList($exception);

        $event->setContext($this->os);

        $event->setContext($this->runtime);

        $event->setTag("server_name", php_uname('n'));

        if ($request) {
            $this->addRequestDetails($event, $request);
        }

        $body = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            "Accept: application/json",
            "Content-Type: application/json",
            $this->createAuthHeader($timestamp),
        ];

        $response = $this->fetch("POST", $this->url, $body, $headers);

        return $response;
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
     * Creates the `X-Sentry-Auth` header.
     *
     * @param int $timestamp
     *
     * @return string
     */
    private function createAuthHeader(int $timestamp)
    {
        return sprintf($this->auth_header, $timestamp);
    }

    /**
     * Populates the given {@see Event} instance with information about the given {@see ServerRequestInterface}.
     *
     * @param Event                  $event
     * @param ServerRequestInterface $request
     */
    protected function addRequestDetails(Event $event, ServerRequestInterface $request)
    {
        $event->setTag("site", $request->getUri()->getHost());

        $event->request = new Request($request->getUri()->__toString(), $request->getMethod());

        $event->request->query_string = $request->getUri()->getQuery();

        $event->request->cookies = $request->getCookieParams();

        $headers = [];

        foreach (array_keys($request->getHeaders()) as $name) {
            $headers[$name] = $request->getHeaderLine($name);
        }

        $event->request->headers = $headers;

        // TODO populate $data with post-data (in whatever format is given)
        // TODO populate $env ?
    }

    /**
     * Creates an {@see ExceptionList} instance from a given {@see Throwable}.
     *
     * @param Throwable $exception
     *
     * @return ExceptionList
     */
    protected function createExceptionList(Throwable $exception): ExceptionList
    {
        $items = [];

        while ($exception) {
            $items[] = $this->createExceptionInfo($exception);

            $exception = $exception->getPrevious();
        }

        return new ExceptionList(array_reverse($items));
    }

    /**
     * Creates an {@see ExceptionInfo} intsance from a given {@see Throwable}.
     *
     * @param Throwable $exception
     *
     * @return ExceptionInfo
     */
    protected function createExceptionInfo(Throwable $exception): ExceptionInfo
    {
        $info = new ExceptionInfo(get_class($exception), $exception->getMessage());

        $info->stacktrace = $this->createStackTrace($exception->getTrace());

        return $info;
    }

    /**
     * Creates a {@see StackTrace} instance from a given PHP stack-trace.
     *
     * @param array $trace PHP stack-trace
     *
     * @return StackTrace
     */
    protected function createStackTrace(array $trace): StackTrace
    {
        $frames = [];

        foreach ($trace as $index => $entry) {
            $frames[] = $this->createStackFrame($entry);
        }

        return new StackTrace(array_reverse($frames));
    }

    /**
     * Creates a {@see StackFrame} instance from a given PHP stack-trace entry.
     *
     * @param array $entry PHP stack-trace entry
     *
     * @return StackFrame
     */
    protected function createStackFrame(array $entry): StackFrame
    {
        $filename = isset($entry["file"])
            ? $entry["file"]
            : "{no file}";

        $function = isset($entry["class"])
            ? $entry["class"] . @$entry["type"] . @$entry["function"]
            : @$entry["function"];

        $lineno = array_key_exists("line", $entry)
            ? (int) $entry["line"]
            : 0;

        $frame = new StackFrame($filename, $function, $lineno);

        if ($filename !== "{no file}") {
            $this->loadContext($frame, $filename, $lineno, 5);
        }

        if (isset($entry['args'])) {
            $frame->vars = $this->extractVars($entry);
        }

        return $frame;
    }

    /**
     * Attempts to load lines of source-code "context" from a PHP script to a {@see StackFrame} instance.
     *
     * @param StackFrame $frame     Sentry Client StackFrame to populate
     * @param string     $filename  path to PHP script
     * @param int        $lineno
     * @param int        $num_lines number of lines of context
     */
    protected function loadContext(StackFrame $frame, string $filename, int $lineno, int $num_lines)
    {
        if (! is_file($filename) || ! is_readable($filename)) {
            return;
        }

        $target = max(0, ($lineno - ($num_lines + 1)));

        $currentLineNumber = $target + 1;

        try {
            $file = new SplFileObject($filename);

            $file->seek($target);

            while (! $file->eof()) {
                $line = rtrim($file->current(), "\r\n");

                if ($currentLineNumber == $lineno) {
                    $frame->context_line = $line;
                } elseif ($currentLineNumber < $lineno) {
                    $frame->pre_context[] = $line;
                } elseif ($currentLineNumber > $lineno) {
                    $frame->post_context[] = $line;
                }

                $currentLineNumber += 1;

                if ($currentLineNumber > $lineno + $num_lines) {
                    break;
                }

                $file->next();
            }
        } catch (\Exception $ex) {
            return;
        }
    }

    /**
     * Extracts a map of parameters names to human-readable values from a given stack-frame.
     *
     * @param array $entry PHP stack-frame entry
     *
     * @return string[] map where parameter name => human-readable value string
     */
    protected function extractVars(array $entry)
    {
        $reflection = $this->getReflection($entry);

        $names = $reflection
            ? $this->getParameterNames($reflection)
            : [];

        $vars = [];

        $values = $this->formatValues($entry['args']);

        foreach ($values as $index => $value) {
            $vars[$names[$index] ?? "#" . ($index + 1)] = $value;
        }

        return $vars;
    }

    /**
     * Attempts to obtain a Function Reflection for a given stack-frame.
     *
     * @param array $entry PHP stack-frame entry
     *
     * @return ReflectionFunctionAbstract|null
     */
    protected function getReflection(array $entry): ?ReflectionFunctionAbstract
    {
        try {
            if (isset($entry["class"])) {
                if (method_exists($entry["class"], $entry["function"])) {
                    return new ReflectionMethod($entry["class"], $entry["function"]);
                } elseif ("::" === $entry["type"]) {
                    return new ReflectionMethod($entry["class"], "__callStatic");
                } else {
                    return new ReflectionMethod($entry["class"], "__call");
                }
            } elseif (function_exists($entry["function"])) {
                return new ReflectionFunction($entry["function"]);
            }
        } catch (ReflectionException $exception) {
            return null;
        }

        return null;
    }

    /**
     * Creates a list of parameter-names for a given Function Reflection.
     *
     * @param ReflectionFunctionAbstract $reflection
     *
     * @return string[] list of parameter names
     */
    protected function getParameterNames(ReflectionFunctionAbstract $reflection): array
    {
        $names = [];

        foreach ($reflection->getParameters() as $param) {
            $names[] = "$" . $param->getName();
        }

        return $names;
    }

    /**
     * Formats an array of raw PHP values as human-readable strings
     *
     * @param mixed[] $values raw PHP values
     *
     * @return string[] formatted values
     */
    protected function formatValues(array $values): array
    {
        $formatted = [];

        foreach ($values as $value) {
            $formatted[] = $this->formatValue($value);
        }

        return $formatted;
    }

    /**
     * @var int maximum length of formatted string-values
     *
     * @see formatValue()
     */
    const MAX_STRING_LENGTH = 200;

    /**
     * Formats any given PHP value as a human-readable string
     *
     * @param mixed $value
     *
     * @return string
     */
    protected function formatValue($value): string
    {
        $type = is_array($value) && is_callable($value)
            ? "callable"
            : strtolower(gettype($value));

        switch ($type) {
            case "boolean":
                return $value ? "true" : "false";

            case "integer":
                return number_format($value, 0, "", "");

            case "double": // (for historical reasons "double" is returned in case of a float, and not simply "float")
                $formatted = sprintf("%.6g", $value);

                return $value == $formatted
                    ? "{$formatted}"
                    : "~{$formatted}";

            case "string":
                $string = strlen($value) > self::MAX_STRING_LENGTH
                    ? substr($value, 0, self::MAX_STRING_LENGTH) . "...[" . strlen($value) . "]"
                    : $value;

                return '"' . addslashes($string) . '"';

            case "array":
                return "array[" . count($value) . "]";

            case "object":
                if ($value instanceof Closure) {
                    $reflection = new ReflectionFunction($value);

                    return "{Closure in " . $reflection->getFileName() . "({$reflection->getStartLine()})}";
                }

                return "{" . ($value instanceof \stdClass ? "object" : get_class($value)) . "}";

            case "resource":
                return "{" . get_resource_type($value) . "}";

            case "resource (closed)":
                return "{unknown type}";

            case "callable":
                return is_object($value[0])
                    ? '{' . get_class($value[0]) . "}->{$value[1]}()"
                    : "{$value[0]}::{$value[1]}()";

            case "null":
                return "null";
        }

        return "{{$type}}"; // "unknown type" and possibly unsupported (future) types
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
            ],
        ]);

        $response = file_get_contents($url, false, $context);

        /**
         * @var array $http_response_header materializes out of thin air
         */

        $status_line = $http_response_header[0];

        preg_match('{HTTP\/\S*\s(\d{3})}', $status_line, $match);

        $status = $match[1];

        if ($status !== "200") {
            throw new RuntimeException("unexpected response status: {$status_line} ({$method} {$url})");
        }

        return $response;
    }

    /**
     * Create run-time context information about this PHP installation.
     *
     * @return RuntimeContext
     */
    private function createRuntimeContext(): RuntimeContext
    {
        return new RuntimeContext(
            "php",       // name
            PHP_VERSION, // version
            phpversion() // raw description
        );
    }

    /**
     * Create the OS context information about this Operating System.
     *
     * @return OSContext
     */
    private function createOSContext(): OSContext
    {
        return new OSContext(
            php_uname("s"), // name
            php_uname("v"), // version
            php_uname("r")  // build
        );
    }
}
