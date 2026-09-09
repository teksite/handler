<?php

namespace Teksite\Handler\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Teksite\Handler\Contracts\ServiceResult as ServiceResultContract;
use UnexpectedValueException;

/**
 * Wraps a service action with optional DB transaction, unified result object,
 * exception logging and success/failure event dispatching.
 */
class ServiceWrapper
{
    private ?Closure $onSuccess = null;
    private ?Closure $onFailure = null;

    /** @var array<string, mixed> */
    private array $eventData = [];

    /**
     * @param bool $useTransaction
     * @param bool $wrapServiceResult
     * @param bool $useHandler
     * @param string|null $connection
     */
    public function __construct(
        private readonly bool $useTransaction = true,
        private readonly bool $wrapServiceResult = true,
        private readonly bool $useHandler = true,
        private ?string       $connection = null,

    )
    {
        $this->connection = $connection ?? config('handler.connection');
    }

    /**
     * @param bool $hasTransaction
     * @param bool $wrapServiceResult
     * @param bool $withHandler
     * @param string|null $connection
     * @return self
     */
    public static function make(?bool $hasTransaction = null, ?bool $wrapServiceResult = null, ?bool $withHandler = null, ?string $connection = null): self
    {
        return new self(
            $hasTransaction ?? config('handler.transaction', true),
            $wrapServiceResult ?? config('handler.service_result', true),
            $withHandler ?? config('handler.wrapper', true),
            $connection,
        );
    }

    /**
     * The action to run on success.
     */
    public function do(Closure $closure): self
    {
        $this->onSuccess = $closure;
        return $this;
    }

    /**
     * The action to run if the primary action fails.
     */
    public function ifFailed(Closure $closure): self
    {
        $this->onFailure = $closure;
        return $this;
    }

    public function withEventData(array $data = []): self
    {
        $this->eventData = array_merge($this->eventData, $data);
        return $this;
    }

    /**
     * Run the transaction (if enabled) on a specific DB connection instead of the default one.
     * Pass null to explicitly use the app's default connection.
     */
    public function onConnection(?string $connection = null): self
    {
        $this->connection = $connection;
        return $this;
    }


    /**
     * @param bool $dispatchSuccessEvent
     * @param bool $dispatchFailureEvent
     * @param array $additionalEventData
     * @return mixed
     * @throws \Throwable
     */
    public function run(
        bool  $dispatchSuccessEvent = false,
        bool  $dispatchFailureEvent = false,
        array $additionalEventData = []
    ): mixed
    {
        if ($this->onSuccess === null) throw new \LogicException("The 'do' closure must be set before calling run.");

        if (!$this->useHandler) return $this->executeAction($this->onSuccess);

        $eventData = array_merge($this->eventData, $additionalEventData);

        try {
            $result = $this->executeWithTransaction();

            if ($dispatchSuccessEvent) {
                $this->dispatchEvent(config('handler.success_event_class'), array_merge(['result' => $result, 'data' => $eventData]));
            }
            return $this->wrapResult($result, true);

        } catch (\Throwable $e) {

            if (config('handler.log', false)) Log::error('Service execution failed.', ['exception' => $e]);

            if ($dispatchFailureEvent) {
                $this->dispatchEvent(config('handler.failure_event_class'), array_merge(['exception' => $e]));
            }

            if ($this->onFailure) {
                $result = $this->executeAction($this->onFailure);
                return $this->wrapResult($result, false);
            }

            return $this->wrapResult(null, false);

        }
    }

    /**
     * Runs the success closure, optionally wrapped in a DB transaction on the configured connection.
     *
     * @throws \Throwable
     */
    private function executeWithTransaction(): mixed
    {
        if (!$this->useTransaction) return $this->executeAction($this->onSuccess);

        return DB::connection($this->connection)->transaction(fn() => $this->executeAction($this->onSuccess));
    }


    private function executeAction(Closure $closure): mixed
    {
        return $closure();
    }

    /**
     * Dispatch an event without letting a failure here affect the actual service result.
     */
    private function dispatchEvent(?string $eventClass, array $data): void
    {
        if (!$eventClass || !class_exists($eventClass)) return;

        try {
            event(app()->make($eventClass, $data));
        } catch (\Throwable $e) {
            if (config('handler.log', false)) {
                Log::error('Failed to dispatch handler event.', [
                    'event'     => $eventClass,
                    'exception' => $e,
                ]);
            }
        }
    }

    private function wrapResult(mixed $result, bool $success): mixed
    {
        if (!$this->wrapServiceResult) return $result;

        $serviceResultClass = config('handler.service_result_class', \Teksite\Handler\Data\ServiceResult::class);

        if (
            !is_string($serviceResultClass)
            || !class_exists($serviceResultClass)
            || !is_a($serviceResultClass, ServiceResultContract::class, true)
        ) {
            throw new UnexpectedValueException("Service result not found or not implement from 'Teksite\Handler\Contracts\ServiceResult'");
        }

        return new $serviceResultClass($success, $result);
    }
}

