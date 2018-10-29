<?php

namespace Kodus\Sentry\Extensions;

use Kodus\Sentry\SentryClientExtension;
use Kodus\Sentry\Model\Event;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * This optional extension removes a specified root-path from paths in stack-traces.
 */
class RootPathRemover implements SentryClientExtension
{
    /**
     * @var string root path (with trailing directory-separator)
     */
    private $root_path;

    /**
     * @param string|null $root_path absolute project root-path (e.g. Composer root path)
     */
    public function __construct(string $root_path)
    {
        $this->root_path = $root_path ? rtrim($root_path, "/\\") . "/" : null;
    }

    public function apply(Event $event, Throwable $error, ?ServerRequestInterface $request): void
    {
        if ($event->exception) {
            foreach ($event->exception->values as $exception_info) {
                foreach ($exception_info->stacktrace->frames as $frame) {
                    if ($frame->filename && strpos($frame->filename, $this->root_path) !== -1) {
                        $frame->abs_path = $frame->filename;
                        $frame->filename = substr($frame->filename, strlen($this->root_path));
                    }
                }
            }
        }
    }
}
