<?php

namespace Kodus\Sentry;

class OSContext implements Context
{
    public $name;
    public $version;
    public $build;

    public function __construct()
    {
        $this->name = php_uname("s");
        $this->version = php_uname("v");
        $this->build = php_uname("r");
    }

    public function getType(): string
    {
        return "os";
    }

    public function jsonSerialize()
    {
        return get_object_vars($this);
    }
}
