<?php

namespace Teksite\Handler\Services;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Teksite\Handler\Contracts\FailureEventContract;
use Teksite\Handler\Contracts\ServiceResultContract;
use Teksite\Handler\Contracts\SuccessEventContract;
use Throwable;
use UnexpectedValueException;

/**
 * Wraps a service action with an optional DB transaction, a unified result object,
 * exception logging and success/failure event dispatching.
 *
 * When the handler is disabled (`withHandler: false` or `handler.wrapper = false`),
 * exceptions are no longer caught and bubble up untouched, which is meant for
 * development and debugging. Transactions, result wrapping and the success event
 * keep following their own settings.
 */
class ServiceWrapper
{

    /**
     * Exceptions Laravel uses for control flow (validation, authorization, HTTP errors).
     * They are never swallowed, so the framework can render its regular response.
     *
     * @var array<int, class-string<Throwable>>
     */
    private const array DONT_CATCH = [
        ValidationException::class,
        AuthorizationException::class,
        AuthenticationException::class,
        HttpExceptionInterface::class,
        HttpResponseException::class,
    ];

    private ?Closure $onSuccess = null;
    private ?Closure $onFailure = null;

    /**
     * @var array<string, mixed>
     */
    private array $eventData = [];

    /**
     * @param bool        $useTransaction
     * @param bool        $wrapServiceResult
     * @param bool        $useHandler
     * @param string|null $connection
     */
    public function __construct(
        private readonly bool $useTransaction = true,
        private readonly bool $wrapServiceResult = true,
        private readonly bool $useHandler = true,
        private ?string $connection = null,
    )
    {
        $this->connection = $connection ?? config('handler.connection');
    }

    /**
     * @param bool        $hasTransaction
     * @param bool        $wrapServiceResult
     * @param bool        $withHandler
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

    public static function withDbTransaction(?bool $wrapServiceResult = null, ?bool $withHandler = null, ?string $connection = null): self
    {
        return self::make(true, $wrapServiceResult, $connection);
    }

    public static function withoutDbTransaction(?bool $wrapServiceResult = null, ?bool $withHandler = null, ?string $connection = null): self
    {
        return self::make(false, $wrapServiceResult, $connection);

    }

    /**
     * The action to run (inside a transaction when enabled).
     */
    public function do(Closure $closure): self
    {
        $this->onSuccess = $closure;

        return $this;
    }

    /**
     * The fallback to run if the primary action fails with a caught exception.
     */
    public function ifFailed(Closure $closure): self
    {
        $this->onFailure = $closure;

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
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
     * @param array<string, mixed> $additionalEventData
     *
     * @throws LogicException           When do() was never called.
     * @throws UnexpectedValueException When the configured service result class is invalid.
     * @throws Throwable                When the handler is disabled or the exception is a control-flow one.
     */
    public function run(bool $dispatchSuccessEvent = false, bool $dispatchFailureEvent = false, array $additionalEventData = []): mixed
    {
        if ($this->onSuccess === null) throw new LogicException("The 'do' closure must be set before calling run.");

        if (!$this->useHandler) {
            $result = $this->executeAction($this->onSuccess);
            return $this->wrapResult($result, null);
        }

        $eventData = array_merge($this->eventData, $additionalEventData);

        try {
            $result = $this->executeWithTransaction();

        } catch (Throwable $e) {
            if (!$this->useHandler || $this->shouldRethrow($e)) throw $e;

            return $this->handleFailure($e, $dispatchFailureEvent, $eventData);
        }

        if ($dispatchSuccessEvent) {
            $this->dispatchEvent(
                config('handler.success_event_class'),
                SuccessEventContract::class,
                [$result, $eventData],
            );
        }
        return $this->wrapResult($result, true);

    }


    /**
     * Runs the success closure, optionally wrapped in a DB transaction on the configured connection.
     *
     * @throws \Throwable
     */
    private function executeWithTransaction(): mixed
    {
        $action = $this->onSuccess;

        if (!$this->useTransaction) return $this->executeAction($action);

        return DB::connection($this->connection)->transaction(fn() => $this->executeAction($action));
    }

    private function executeAction(Closure $closure): mixed
    {
        return $closure();
    }


    private function shouldRethrow(Throwable $e): bool
    {
        foreach (self::DONT_CATCH as $class) {
            if ($e instanceof $class) return true;
        }
        return false;
    }


    /**
     * @param array<string, mixed> $eventData
     */
    private function handleFailure(Throwable $e, bool $dispatchFailureEvent, array $eventData): mixed
    {
        if (config('handler.log', true)) Log::error('Service execution failed.', ['exception' => $e]);

        if ($dispatchFailureEvent) {
            $this->dispatchEvent(config('handler.failure_event_class'), FailureEventContract::class, [$e, $eventData]);
        }

        $action = $this->onFailure;

        $result = $this->onFailure !== null ? $this->executeAction($action) : null;

        return $this->wrapResult($result, false);
    }

    /**
     * Dispatch an event without letting a failure here affect the actual service result.
     *
     * An empty class name disables the event. A class that does not exist or does not
     * implement the expected contract is skipped with a warning.
     *
     * @param array<int, mixed> $arguments Positional constructor arguments.
     */
    private function dispatchEvent(mixed $eventClass, string $contract, array $arguments): void
    {
        if (!is_string($eventClass) || $eventClass === '') return;

        try {
            if (!is_a($eventClass, $contract, true)) {
                Log::warning(sprintf('Handler event class [%s] was skipped: it must exist and implement [%s].', $eventClass, $contract));
                return;
            }
            event(new $eventClass(...$arguments));
        } catch (Throwable $e) {
            if (config('handler.log', true)) {
                Log::error('Failed to dispatch handler event.', ['event' => $eventClass, 'exception' => $e,]);
            }
        }
    }

    private function wrapResult(mixed $result, bool|null $success): mixed
    {
        if (!$this->wrapServiceResult) return $result;

        $serviceResultClass = config('handler.service_result_class', \Teksite\Handler\Data\ServiceResult::class);

        if (!is_string($serviceResultClass) || !is_a($serviceResultClass, ServiceResultContract::class, true)) {
            throw new UnexpectedValueException(sprintf(
                'The configured service result class must implement [%s].',
                ServiceResultContract::class,
            ));
        }

        return new $serviceResultClass($success, $result);
    }
}

