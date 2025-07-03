<?php
namespace Teksite\Handler\Actions;
class ServiceResult
{
    /**
     * @param bool $success
     * @param mixed $result
     * @param int|null $successStatus
     */
    public function __construct(public bool $success, public mixed $result, ?int $successStatus =null)
    {
    }
}
