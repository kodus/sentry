<?php

namespace Kodus\Sentry\Extensions;

use Kodus\Sentry\Model\Event;
use Kodus\Sentry\Model\OSContext;
use Kodus\Sentry\Model\RuntimeContext;
use Kodus\Sentry\SentryClientExtension;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * This built-in extension reports OS and PHP run-time information.
 */
class EnvironmentReporter implements SentryClientExtension
{
    /**
     * @var RuntimeContext
     */
    protected $runtime;

    /**
     * @var OSContext
     */
    protected $os;

    public function __construct()
    {
        $this->runtime = $this->createRuntimeContext();

        $this->os = $this->createOSContext();
    }

    /**
     * Applies transformations to the `$event`, and/or extracts additional information
     * from the `$exception` and `$request` instances and applies them to the `$event`.
     *
     * @param Event                       $event
     * @param Throwable                   $exception
     * @param ServerRequestInterface|null $request
     */
    public function apply(Event $event, Throwable $exception, ?ServerRequestInterface $request): void
    {
        $event->addContext($this->os);

        $event->addContext($this->runtime);

        $event->addTag("server_name", php_uname('n'));
    }

    /**
     * Create run-time context information about this PHP installation.
     *
     * @return RuntimeContext
     */
    protected function createRuntimeContext(): RuntimeContext
    {
        $name = "php";

        $raw_description = PHP_VERSION;

        preg_match("#^\d+(\.\d+){2}#", $raw_description, $version);

        return new RuntimeContext($name, $version[0], $raw_description);
    }

    /**
     * Create the OS context information about this Operating System.
     *
     * @return OSContext
     */
    protected function createOSContext(): OSContext
    {
        $name = php_uname("s");
        $version = php_uname("v");
        $build = php_uname("r");

        return new OSContext($name, $version, $build);
    }
}
