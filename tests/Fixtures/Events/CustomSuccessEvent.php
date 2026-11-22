<?php

namespace Teksite\Handler\Tests\Fixtures\Events;

use Teksite\Handler\Contracts\SuccessEventContract;

class CustomSuccessEvent implements SuccessEventContract
{
    public function __construct(
        public mixed $payload,
        public array $context = [],
    ) {}
}
