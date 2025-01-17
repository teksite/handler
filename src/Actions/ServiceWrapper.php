<?php

namespace Teksite\Handler\Actions;


use Closure;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ServiceWrapper
{
    public function __invoke(Closure $closure, ?Closure $errorHandler = null, bool $hasTransaction = true, bool $withHandler = true): ServiceResult
    {
        if ($withHandler) {
            return $this->executeWithHandler($closure, $errorHandler, $hasTransaction);
        }

        return $this->onlyExecute($closure);
    }

    private function onlyExecute(Closure $closure): ServiceResult
    {
        return new ServiceResult(true, $closure());
    }

    private function executeWithHandler(Closure $closure, ?Closure $errorHandler, bool $hasTransaction): ServiceResult
    {
        if ($hasTransaction) {
            return $this->executeWithTransaction($closure, $errorHandler);
        }
        return $this->executeWithoutTransaction($closure, $errorHandler);
    }

    private function executeWithTransaction(Closure $closure, ?Closure $errorHandler): ServiceResult
    {

        DB::beginTransaction();
        try {
            $result = new ServiceResult(true, $closure());
            DB::commit();
            return $result;
        } catch (Exception $exception) {
            DB::rollBack();
            return $this->handleError($errorHandler , $exception);
        }
    }

    private function executeWithoutTransaction(Closure $closure, ?Closure $errorHandler): ServiceResult
    {
        try {
            return new ServiceResult(true, $closure());
        } catch (Exception $exception) {
            return $this->handleError($errorHandler , $exception);
        }
    }

    private function handleError(?Closure $errorHandler , Exception $exception): ServiceResult
    {
        if ($errorHandler) $errorHandler();
        Log::error($exception);
        return new ServiceResult(false, null);
    }
}
