<?php
namespace Teksite\Handler\Actions;
class ServiceResult
{
    public function __construct(public bool $success, public mixed $result)
    {
    }
}
