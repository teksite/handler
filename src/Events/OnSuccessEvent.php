<?php

namespace Teksite\Handler\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Teksite\Handler\Contracts\SuccessEventContract;

class OnSuccessEvent implements SuccessEventContract
{
    use Dispatchable, SerializesModels;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(public readonly mixed $result, public readonly array $data = [],) {}

}
