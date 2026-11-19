<?php

declare(strict_types=1);

namespace hkyss\Sonar\Tests\Integration;

use Illuminate\Container\Container;

final class FakeApplication extends Container
{
    public function __construct(private readonly bool $console = false)
    {
    }

    public function runningInConsole(): bool
    {
        return $this->console;
    }
}
