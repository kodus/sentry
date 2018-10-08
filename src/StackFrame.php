<?php

namespace Kodus\Sentry;

use JsonSerializable;

/**
 * @link https://docs.sentry.io/clientdev/interfaces/exception/
 */
class StackFrame implements JsonSerializable
{
    /**
     * @var string project-relative file-name/path
     */
    public $filename;

    /**
     * @var string
     */
    public $function;

    /**
     * @var int|null
     */
    public $lineno;

    /**
     * @var string|null
     */
    public $context_line;

    /**
     * @var string[]
     */
    public $pre_context = [];

    /**
     * @var string[]
     */
    public $post_context = [];

    /**
     * @var string[] map where parameter-name => string representation of value
     */
    public $vars = [];

    public function __construct(
        string $filename,
        string $function,
        ?int $lineno
    ) {
        $this->filename = $filename;
        $this->function = $function;
        $this->lineno = $lineno;
    }

    /**
     * @internal
     */
    public function jsonSerialize()
    {
        return array_filter(get_object_vars($this));
    }
}
