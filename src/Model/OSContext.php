<?php

namespace Kodus\Sentry\Model;

class OSContext implements Context
{
    /**
     * @var string
     */
    public $name;

    /**
     * @var string
     */
    public $version;

    /**
     * @var string
     */
    public $build;

    public function __construct(string $name, string $version, string $build)
    {
        $this->name = $name;
        $this->version = $version;
        $this->build = $build;
    }

    public function getType(): string
    {
        return "os";
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
