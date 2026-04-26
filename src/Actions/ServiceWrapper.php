<?php

namespace Teksite\Handler\Actions;


use Closure;
use Exception;
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
    public function __construct(private bool $useTransaction = true, private bool $wrapServiceResult = true, private bool $useHandler = true)
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
     * @return mixed
     * @throws \Throwable
     */
    public function run(): mixed
    {
        if (!$this->onSuccess) throw new \LogicException("The 'do' closure must be set before calling run.");

        if (!$this->useHandler) return $this->executeAction($this->onSuccess);

        try {
            $result = $this->useTransaction
                ? DB::transaction(fn() => $this->executeAction($this->onSuccess))
                : $this->executeAction($this->onSuccess);

            return $this->wrapResult($result, true);
        } catch (\Throwable $e) {
            Log::error($e);

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

        if (!class_exists(ServiceResult::class)) return $result;

        return new ServiceResult($success, $result);
    }
}

