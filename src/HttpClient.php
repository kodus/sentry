<?php

namespace Kodus\Sentry;

use RuntimeException;

/**
 * @internal minimalist HTTP client for internal use.
 */
class HttpClient
{
    /**
     * @param string $method
     * @param string $url
     * @param string $body
     * @param array  $headers
     *
     * @return string
     */
    public function fetch(string $method, string $url, string $body, array $headers = []): string
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
}
