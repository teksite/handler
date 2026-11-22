<?php

namespace Teksite\Handler\Contracts;

interface ServiceResultContract
{
    public function __construct(bool $success, mixed $result,);


    public function isSuccess(): bool;

    public function isFailed(): bool;

    public function getResult(): mixed;

    /**
     * @return string|array<int|string, mixed>|null
     */
    public function getErrors(): string|array|null;

    /**
     * HTTP status to use for a successful response, null to use the default.
     */
    public function getSuccessStatus(): ?int;

    /**
     * HTTP status to use for a failed response, null to use the default.
     */
    public function getFailedStatus(): ?int;
}
