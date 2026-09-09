<?php

namespace Teksite\Handler\Contracts;

interface ServiceResultContract
{
    public function __construct(bool $success, mixed $result,);
}
