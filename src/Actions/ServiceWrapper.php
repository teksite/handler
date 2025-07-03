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
     * @param int $successStatus
     * @return ServiceResult
     */
    public function __invoke(Closure $closure, ?Closure $errorHandler = null, bool $hasTransaction = true, bool $withHandler = true ,int $successStatus=200): ServiceResult
    {
        $isActiveWrapper=config('handler-settings.wrapper' ,true);

        if ($withHandler && $isActiveWrapper) {
            return $this->executeWithHandler($closure, $errorHandler, $hasTransaction ,$successStatus);
        }

        return $this->onlyExecute($closure);
    }

    /**
     * @param Closure $closure
     * @return ServiceResult
     */
    private function onlyExecute(Closure $closure): ServiceResult
    {
        return new ServiceResult(true, $closure() );
    }

    /**
     * @param Closure $closure
     * @param Closure|null $errorHandler
     * @param bool $hasTransaction
     * @param int $successStatus
     * @return ServiceResult
     */
    private function executeWithHandler(Closure $closure, ?Closure $errorHandler, bool $hasTransaction ,int $successStatus): ServiceResult
    {
        if ($hasTransaction) {
            return $this->executeWithTransaction($closure, $errorHandler ,$successStatus);
        }
        return $this->executeWithoutTransaction($closure, $errorHandler ,$successStatus);
    }

    /**
     * @param Closure $closure
     * @param Closure|null $errorHandler
     * @param int $successStatus
     * @return ServiceResult
     */
    private function executeWithTransaction(Closure $closure, ?Closure $errorHandler , int $successStatus): ServiceResult
    {

        DB::beginTransaction();
        try {
            $result = new ServiceResult(true, $closure() ,$successStatus);
            DB::commit();
            return $result;
        } catch (Exception $exception) {
            DB::rollBack();
            return $this->handleError($errorHandler , $exception , 500);
        }
    }

    /**
     * @param Closure $closure
     * @param Closure|null $errorHandler
     * @param int $successStatus
     * @return ServiceResult
     */
    private function executeWithoutTransaction(Closure $closure, ?Closure $errorHandler ,int $successStatus): ServiceResult
    {
        try {
            return new ServiceResult(true, $closure() ,$successStatus);
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
        return new ServiceResult(false, null , 500);
    }
}
