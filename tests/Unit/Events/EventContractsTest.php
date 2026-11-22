<?php

namespace Teksite\Handler\Tests\Unit\Events;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Teksite\Handler\Contracts\FailureEventContract;
use Teksite\Handler\Contracts\SuccessEventContract;
use Teksite\Handler\Events\OnFailureEvent;
use Teksite\Handler\Events\OnSuccessEvent;
use Teksite\Handler\Tests\TestCase;

class EventContractsTest extends TestCase
{
    #[Test]
    public function the_default_success_event_implements_its_contract_and_exposes_its_values(): void
    {
        $event = new OnSuccessEvent('payload', ['actor' => 'system']);

        $this->assertInstanceOf(SuccessEventContract::class, $event);
        $this->assertSame('payload', $event->result);
        $this->assertSame(['actor' => 'system'], $event->data);
    }

    #[Test]
    public function the_default_failure_event_implements_its_contract_and_exposes_its_values(): void
    {
        $exception = new RuntimeException('boom');

        $event = new OnFailureEvent($exception, ['actor' => 'system']);

        $this->assertInstanceOf(FailureEventContract::class, $event);
        $this->assertSame($exception, $event->exception);
        $this->assertSame(['actor' => 'system'], $event->data);
    }

    #[Test]
    public function the_data_argument_is_optional(): void
    {
        $this->assertSame([], (new OnSuccessEvent('payload'))->data);
        $this->assertSame([], (new OnFailureEvent(new RuntimeException('boom')))->data);
    }

    #[Test]
    public function the_shipped_config_points_to_the_default_events(): void
    {
        $this->assertSame(OnSuccessEvent::class, config('handler.success_event_class'));
        $this->assertSame(OnFailureEvent::class, config('handler.failure_event_class'));
    }
}
