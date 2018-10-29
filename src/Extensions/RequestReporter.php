<?php

namespace Kodus\Sentry\Extensions;

use Kodus\Sentry\Model\Event;
use Kodus\Sentry\Model\Request;
use Kodus\Sentry\SentryClientExtension;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * This extension reports details about the (PSR-7) HTTP Request.
 */
class RequestReporter implements SentryClientExtension
{
    public function apply(Event $event, Throwable $exception, ?ServerRequestInterface $request): void
    {
        if (! $request) {
            return;
        }

        $event->addTag("site", $request->getUri()->getHost());

        $event->request = new Request($request->getUri()->__toString(), $request->getMethod());

        $event->request->query_string = $request->getUri()->getQuery();

        $event->request->cookies = $request->getCookieParams();

        $headers = [];

        foreach (array_keys($request->getHeaders()) as $name) {
            $headers[$name] = $request->getHeaderLine($name);
        }

        $event->request->headers = $headers;
    }
}
