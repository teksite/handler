<?php

namespace Teksite\Handler\Data;

use Teksite\Handler\Contracts\ServiceResultContract;

final readonly class ServiceResult implements ServiceResultContract
{
    /**
     * @param string|array<int|string, mixed>|null $errors
     */
    public function __construct(
        public bool|null $success,
        public mixed $result = null,
        public string|array|null $errors = null,
        public ?int $successStatus = null,
        public ?int $failedStatus = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->success === true;
    }

    public function isFailed(): bool
    {
        return $this->success ===false;
    }

    public function isUnknown(): bool
    {
        return $this->success === null;
    }


    public function getResult(): mixed
    {
        return $this->result;
    }

    public function getErrors(): string|array|null
    {
        return $this->errors;
    }

    public function getSuccessStatus(): ?int
    {
        return $this->successStatus;
    }

    public function getFailedStatus(): ?int
    {
        return $this->failedStatus;
    }
}
