<?php

namespace Teksite\Handler\Contracts;

interface ServiceResult
{
    public function __construct(bool $success,mixed $result);


}
