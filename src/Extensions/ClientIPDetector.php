<?php

namespace Kodus\Sentry\Extensions;

use Kodus\Sentry\Model\Event;
use Kodus\Sentry\SentryClientExtension;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * This optional extension performs client IP detection.
 */
class ClientIPDetector implements SentryClientExtension
{
    /**
     * List of trusted header-names from which the User's IP may be obtained.
     *
     * @var string[] map where header-name => regular expression pattern
     *
     * @see applyRequestDetails()
     */
    public $user_ip_headers;

    /**
     * @param array|null $user_ip_headers optional map where header-name => regular expression pattern
     */
    public function __construct(?array $user_ip_headers = null) {
        $this->user_ip_headers = $user_ip_headers
            ?: [
                "X-Forwarded-For" => '/^([^,\s$]+)/i',  // https://en.wikipedia.org/wiki/X-Forwarded-For
                "Forwarded"       => '/for=([^;,]+)/i', // https://tools.ietf.org/html/rfc7239
            ];
    }

    public function apply(Event $event, Throwable $exception, ?ServerRequestInterface $request): void
    {
        if ($request) {
            $event->user->ip_address = $this->detectUserIP($request);
        }
    }

    /**
     * Attempts to discover the client's IP address, from proxy-headers if necessary.
     *
     * Note that concerns about trusted proxies are ignored by this implementation - if
     * somebody spoofs their IP, it may get logged, but that's not a security issue for
     * this use-case, since we're reporting only.
     *
     * @param ServerRequestInterface $request
     *
     * @return string client IP address (or 'unknown')
     */
    protected function detectUserIP(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();

        if (isset($server["REMOTE_ADDR"])) {
            if ($this->isValidIP($server["REMOTE_ADDR"])) {
                return $server["REMOTE_ADDR"]; // prioritize an IP provided by the CGI back-end
            }
        }

        foreach ($this->user_ip_headers as $name => $pattern) {
            if ($request->hasHeader($name)) {
                $value = $request->getHeaderLine($name);

                if (preg_match_all($pattern, $value, $matches) !== false) {
                    foreach ($matches[1] as $match) {
                        $ip = trim(preg_replace('/\:\d+$/', '', trim($match, '"')), '[]');

                        if ($this->isValidIP($ip)) {
                            return $ip; // return the first matching valid IP
                        }
                    }
                }
            }
        }

        return "unknown";
    }

    /**
     * Validates a detected client IP address.
     *
     * @param string $ip
     *
     * @return bool
     */
    protected function isValidIP(string $ip): bool
    {
        return filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4            // accept IP v4
                | FILTER_FLAG_IPV6          // accept IP v6
                | FILTER_FLAG_NO_PRIV_RANGE // reject private IPv4 ranges
                | FILTER_FLAG_NO_RES_RANGE  // reject reserved IPv4 ranges
            ) !== false;
    }
}
