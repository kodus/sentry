<?php

namespace Kodus\Sentry\Model;

use JsonSerializable;

/**
 * @link https://docs.sentry.io/clientdev/interfaces/exception/
 */
class ExceptionInfo implements JsonSerializable
{
    /**
     * @var string
     */
    public $type;

    /**
     * @var string
     */
    public $value;

    /**
     * @var StackTrace
     */
    public $stacktrace;

    public function __construct(string $type, string $value, StackTrace $stacktrace)
    {
        $this->type = $type;
        $this->value = $value;
        $this->stacktrace = $stacktrace;
    }

    /**
     * @internal
     */
    public function jsonSerialize(): array
    {
        return array_filter(get_object_vars($this));
    }
}
