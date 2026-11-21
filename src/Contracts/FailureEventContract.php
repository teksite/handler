<?php

namespace Teksite\Handler\Contracts;

use Throwable;

/**
 * Contract for the event dispatched by ServiceWrapper after a caught failure.
 *
 * Any class set in `handler.failure_event_class` must implement it. The wrapper
 * instantiates the event positionally, so parameter names are free to differ.
 */
interface FailureEventContract
{
    /**
     * @param Throwable            $exception The exception that made the wrapped action fail.
     * @param array<string, mixed> $data      Extra data given through withEventData() or run().
     */
    public function __construct(Throwable $exception, array $data = []);
}
