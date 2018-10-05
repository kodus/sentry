<?php

namespace Kodus\Sentry;

class RuntimeContext implements Context
{
    public $name;
    public $version;

    public function __construct()
    {
        $this->name = "php";
        $this->version = phpversion();
    }

    public function getType(): string
    {
        return "runtime";
    }

    public function jsonSerialize()
    {
        return get_object_vars($this);
    }
}
