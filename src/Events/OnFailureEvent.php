<?php

namespace Teksite\Handler\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Teksite\Handler\Contracts\FailureEventContract;
use Throwable;

class OnFailureEvent implements FailureEventContract
{
    use Dispatchable, SerializesModels;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(public readonly Throwable $exception, public readonly array $data = [],) {}

}
