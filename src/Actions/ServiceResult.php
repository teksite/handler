<?php
namespace Teksite\Handler\Actions;
class ServiceResult
{
    /**
     * @param bool $success
     * @param mixed $result
     */
    public function __construct(public bool $success, public mixed $result)
    {
    }
}
