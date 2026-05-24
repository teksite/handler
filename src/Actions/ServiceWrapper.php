<?php

namespace Teksite\Handler\Actions;


use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ServiceWrapper
{
    private Closure|null $onSuccess = null;
    private Closure|null $onFailure = null;

    /**
     * @param bool $useTransaction
     * @param bool $useHandler
     * @param bool $wrapServiceResult
     */
    public function __construct(private readonly bool $useTransaction = true, private readonly bool $wrapServiceResult = true, private readonly bool $useHandler = true)
    {
    }

    /**
     * @param bool $hasTransaction
     * @param bool $withHandler
     * @param bool $wrapServiceResult
     * @return self
     */
    public static function make(bool $hasTransaction = true, bool $wrapServiceResult = true, bool $withHandler = true): self
    {
        return new self(
            config('handler-settings.transaction', $hasTransaction),
            config('handler-settings.service_result', $wrapServiceResult),
            config('handler-settings.wrapper', $withHandler),
        );
    }

    public function do(Closure $closure): self
    {
        $this->onSuccess = $closure;
        return $this;
    }

    public function ifFailed(Closure $closure): self
    {
        $this->onFailure = $closure;
        return $this;
    }

    /**
     * @param bool $dispatchSuccessEvent
     * @param bool $dispatchFailureEvent
     * @return mixed
     * @throws \Throwable
     */
    public function run(bool $dispatchSuccessEvent  =false , bool $dispatchFailureEvent  =true): mixed
    {
        if (!$this->onSuccess) {
            throw new \LogicException("The 'do' closure must be set before calling run.");
        }

        if (!$this->useHandler) {
            return $this->executeAction($this->onSuccess);
        }

        $failureEventClass = config('handler-settings.failure_event_class');
        $successEventClass = config('handler-settings.success_event_class');

        try {
            $result = $this->useTransaction
                ? DB::transaction(fn() => $this->executeAction($this->onSuccess))
                : $this->executeAction($this->onSuccess);

            if ($dispatchSuccessEvent && $successEventClass && class_exists($successEventClass)) {
                app()->make($successEventClass);
            }

            return $this->wrapResult($result, true);
        } catch (\Throwable $e) {
            Log::error($e->getMessage(), ['trace' => $e->getTraceAsString()]);

            if ($dispatchFailureEvent && $failureEventClass && class_exists($failureEventClass)) {
                app()->make($failureEventClass, ['exception' => $e]);
            }


            if ($this->onFailure) {
                $result = $this->executeAction($this->onFailure);
                return $this->wrapResult($result, false);
            }

            throw $e;
        }
    }

    private function executeAction(Closure $closure): mixed
    {
        return $closure();
    }

    private function wrapResult(mixed $result, bool $success): mixed
    {
        if (!$this->wrapServiceResult) return $result;

        $serviceResultClass = config('handler-settings.service_result_class', \Teksite\Handler\Actions\ServiceResult::class);

        if (!class_exists($serviceResultClass)) return $result;

        return new $serviceResultClass($success, $result);
    }
}

