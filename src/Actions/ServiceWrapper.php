<?php

namespace Teksite\Handler\Actions;


use Closure;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ServiceWrapper
{
    /**
     * @param Closure $closure
     * @param Closure|null $errorHandler
     * @param bool $hasTransaction
     * @param bool $withHandler
     * @return ServiceResult
     */
    public function __invoke(Closure $closure, ?Closure $errorHandler = null, bool $hasTransaction = true, bool $withHandler = true): ServiceResult
    {
       return $this->execute($closure, $errorHandler, $hasTransaction, $withHandler);
    }

    public function execute(Closure $closure, ?Closure $errorHandler = null, bool $hasTransaction = true, bool $withHandler = true): ServiceResult
    {
        $isActiveWrapper=config('handler-settings.wrapper' ,true);

        if ($withHandler && $isActiveWrapper) {
            return $this->executeWithHandler($closure, $errorHandler, $hasTransaction);
        }

        return $this->onlyExecute($closure);
    }

    /**
     * @param Closure $closure
     * @return ServiceResult
     */
    private function onlyExecute(Closure $closure): ServiceResult
    {
        return new ServiceResult(true, $closure());
    }

    /**
     * @param Closure $closure
     * @param Closure|null $errorHandler
     * @param bool $hasTransaction
     * @return ServiceResult
     */
    private function executeWithHandler(Closure $closure, ?Closure $errorHandler, bool $hasTransaction): ServiceResult
    {
        if ($hasTransaction) {
            return $this->executeWithTransaction($closure, $errorHandler);
        }
        return $this->executeWithoutTransaction($closure, $errorHandler);
    }

    /**
     * @param Closure $closure
     * @param Closure|null $errorHandler
     * @return ServiceResult
     */
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

    /**
     * @param Closure $closure
     * @param Closure|null $errorHandler
     * @return ServiceResult
     */
    private function executeWithoutTransaction(Closure $closure, ?Closure $errorHandler): ServiceResult
    {
        try {
            return new ServiceResult(true, $closure());
        } catch (Exception $exception) {
            return $this->handleError($errorHandler , $exception);
        }
    }

    /**
     * @param Closure|null $errorHandler
     * @param Exception $exception
     * @return ServiceResult
     */
    private function handleError(?Closure $errorHandler , Exception $exception): ServiceResult
    {
        if ($errorHandler) $errorHandler();
        Log::error($exception);
        return new ServiceResult(false, null);
    }
}
