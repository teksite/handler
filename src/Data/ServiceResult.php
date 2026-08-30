<?php

namespace Teksite\Handler\Data;

use Teksite\Handler\contracts\ServiceResult as contract;

final readonly class ServiceResult implements contract
{
    /**
     * @param bool $success
     * @param mixed $result
     */
    public function __construct(public bool $success, public mixed $result)
    {
    }
}
