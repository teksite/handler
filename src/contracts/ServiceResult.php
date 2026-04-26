<?php

namespace Teksite\Handler\contracts;

interface ServiceResult
{
    public function __construct(bool $success,mixed $result);


}
