<?php

namespace Teksite\Handler\Tests\Fixtures\Events;

/**
 * Deliberately does not implement SuccessEventContract / FailureEventContract.
 */
class InvalidEvent
{
    public function __construct(
        public mixed $result = null,
        public array $data = [],
    ) {}
}
