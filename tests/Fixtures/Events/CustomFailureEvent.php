<?php

namespace Teksite\Handler\Tests\Fixtures\Events;

use Teksite\Handler\Contracts\FailureEventContract;
use Throwable;

class CustomFailureEvent implements FailureEventContract
{
    public function __construct(
        public Throwable $error,
        public array $context = [],
    ) {}
}
