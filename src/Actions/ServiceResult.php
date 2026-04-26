<?php

namespace Teksite\Handler\Actions;

use Teksite\Handler\contracts\ServiceResult as contract;

class ServiceResult implements contract
{
    /**
     * @param bool $success
     * @param mixed $result
     */
    public function __construct(public bool $success, public mixed $result)
    {
    }
}
