<?php

namespace Teksite\Handler\Contracts;

/**
 * Contract for the event dispatched by ServiceWrapper after a successful run.
 *
 * Any class set in `handler.success_event_class` must implement it. The wrapper
 * instantiates the event positionally, so parameter names are free to differ.
 */
interface SuccessEventContract
{
    /**
     * @param mixed                $result Value returned by the wrapped action.
     * @param array<string, mixed> $data   Extra data given through withEventData() or run().
     */
    public function __construct(mixed $result, array $data = []);
}
