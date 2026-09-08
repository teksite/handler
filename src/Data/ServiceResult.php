<?php

namespace Teksite\Handler\Data;

use Teksite\Handler\Contracts\ServiceResult as contract;

final readonly class ServiceResult implements contract
{
    /**
     * @param bool $success
     * @param mixed $result
     * @param string|array|null $errors
     * @param int|null $successStatus
     * @param int|null $failedStatus
     */
    public function __construct(
        public bool              $success,
        public mixed             $result = null,
        public string|array|null $errors = null,
        public ?int              $successStatus = null,
        public ?int              $failedStatus = null,
    ) {}
}
