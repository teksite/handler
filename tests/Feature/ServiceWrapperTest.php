<?php

namespace Teksite\Handler\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Teksite\Handler\Data\ServiceResult;
use Teksite\Handler\Events\OnFailureEvent;
use Teksite\Handler\Events\OnSuccessEvent;
use Teksite\Handler\Services\ServiceWrapper;
use Teksite\Handler\Tests\Fixtures\Models\User;
use Teksite\Handler\Tests\TestCase;
use UnexpectedValueException;

class ServiceWrapperTest extends TestCase
{
    #[Test]
    public function a_successful_action_is_wrapped_in_a_service_result(): void
    {
        $result = ServiceWrapper::make()
            ->do(fn () => 'created')
            ->run();

        $this->assertInstanceOf(ServiceResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame('created', $result->result);
    }

    #[Test]
    public function it_returns_the_raw_value_when_service_result_wrapping_is_disabled(): void
    {
        $result = ServiceWrapper::make(wrapServiceResult: false)
            ->do(fn () => 'created')
            ->run();

        $this->assertSame('created', $result);
    }

    #[Test]
    public function the_if_failed_closure_runs_when_the_main_action_throws(): void
    {
        $result = ServiceWrapper::make()
            ->do(fn () => throw new RuntimeException('boom'))
            ->ifFailed(fn () => ['error' => 'fallback'])
            ->run();

        $this->assertInstanceOf(ServiceResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertSame(['error' => 'fallback'], $result->result);
    }

    #[Test]
    public function it_wraps_a_null_result_when_the_action_fails_and_no_if_failed_closure_is_set(): void
    {
        $result = ServiceWrapper::make()
            ->do(fn () => throw new RuntimeException('boom'))
            ->run();

        $this->assertInstanceOf(ServiceResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertNull($result->result);
    }

    #[Test]
    public function run_throws_a_logic_exception_when_the_do_closure_was_never_set(): void
    {
        $this->expectException(LogicException::class);

        ServiceWrapper::make()->run();
    }

    #[Test]
    public function disabling_the_handler_bypasses_the_try_catch_and_lets_exceptions_bubble_up(): void
    {
        $this->expectException(RuntimeException::class);

        ServiceWrapper::make(withHandler: false)
            ->do(fn () => throw new RuntimeException('boom'))
            ->run();
    }

    #[Test]
    public function disabling_the_handler_returns_the_raw_success_value_unwrapped(): void
    {
        $result = ServiceWrapper::make(withHandler: false)
            ->do(fn () => 'raw-value')
            ->run();

        $this->assertSame('raw-value', $result);
    }

    #[Test]
    public function the_action_runs_inside_a_database_transaction_by_default_and_rolls_back_on_failure(): void
    {
        ServiceWrapper::make()
            ->do(function () {
                User::create(['name' => 'Ada']);
                throw new RuntimeException('boom after insert');
            })
            ->run();

        $this->assertSame(0, User::count());
    }

    #[Test]
    public function a_successful_action_inside_a_transaction_persists_its_changes(): void
    {
        ServiceWrapper::make()
            ->do(fn () => User::create(['name' => 'Ada']))
            ->run();

        $this->assertSame(1, User::count());
    }

    #[Test]
    public function disabling_the_transaction_leaves_partial_writes_in_place_after_a_failure(): void
    {
        ServiceWrapper::make(hasTransaction: false)
            ->do(function () {
                User::create(['name' => 'Ada']);
                throw new RuntimeException('boom after insert');
            })
            ->run();

        $this->assertSame(1, User::count());
    }

    #[Test]
    public function it_dispatches_the_configured_success_event_when_requested(): void
    {
        Event::fake([OnSuccessEvent::class]);

        ServiceWrapper::make()
            ->do(fn () => 'payload')
            ->run(dispatchSuccessEvent: true);

        Event::assertDispatched(OnSuccessEvent::class, fn (OnSuccessEvent $event) => $event->result === 'payload');
    }

    #[Test]
    public function it_dispatches_the_configured_failure_event_when_requested(): void
    {
        Event::fake([OnFailureEvent::class]);

        ServiceWrapper::make()
            ->do(fn () => throw new RuntimeException('boom'))
            ->run(dispatchFailureEvent: true);

        Event::assertDispatched(OnFailureEvent::class, fn (OnFailureEvent $event) => $event->exception->getMessage() === 'boom');
    }

    #[Test]
    public function it_does_not_dispatch_any_event_by_default(): void
    {
        Event::fake([OnSuccessEvent::class, OnFailureEvent::class]);

        ServiceWrapper::make()->do(fn () => 'payload')->run();
        ServiceWrapper::make()->do(fn () => throw new RuntimeException('boom'))->run();

        Event::assertNotDispatched(OnSuccessEvent::class);
        Event::assertNotDispatched(OnFailureEvent::class);
    }

    #[Test]
    public function additional_event_data_is_merged_into_the_dispatched_events_data_property(): void
    {
        Event::fake([OnSuccessEvent::class]);

        ServiceWrapper::make()
            ->withEventData(['actor' => 'system'])
            ->do(fn () => 'payload')
            ->run(dispatchSuccessEvent: true, additionalEventData: ['request_id' => 'abc-123']);

        Event::assertDispatched(OnSuccessEvent::class, function (OnSuccessEvent $event) {
            return $event->result === 'payload'
                && $event->data['actor'] === 'system'
                && $event->data['request_id'] === 'abc-123';
        });
    }

    #[Test]
    public function a_failure_while_dispatching_the_event_does_not_affect_the_service_result(): void
    {
        config()->set('handler.success_event_class', 'This\\Class\\Does\\Not\\Exist');

        $result = ServiceWrapper::make()
            ->do(fn () => 'payload')
            ->run(dispatchSuccessEvent: true);

        $this->assertTrue($result->success);
        $this->assertSame('payload', $result->result);
    }

    #[Test]
    public function it_throws_when_the_configured_service_result_class_does_not_implement_the_contract(): void
    {
        config()->set('handler.service_result_class', \stdClass::class);

        $this->expectException(UnexpectedValueException::class);

        ServiceWrapper::make()->do(fn () => 'payload')->run();
    }

    #[Test]
    public function it_can_run_the_transaction_on_an_explicitly_configured_connection(): void
    {
        config()->set('database.connections.secondary', config('database.connections.testing'));

        $result = ServiceWrapper::make(connection: 'secondary')
            ->do(fn () => DB::connection('secondary')->getName())
            ->run();

        $this->assertSame('secondary', $result->result);
    }
}
