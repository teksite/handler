<?php

namespace Teksite\Handler\Tests\Feature;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Teksite\Handler\Data\ServiceResult;
use Teksite\Handler\Events\OnFailureEvent;
use Teksite\Handler\Events\OnSuccessEvent;
use Teksite\Handler\Services\ServiceWrapper;
use Teksite\Handler\Tests\Fixtures\Events\CustomFailureEvent;
use Teksite\Handler\Tests\Fixtures\Events\CustomSuccessEvent;
use Teksite\Handler\Tests\Fixtures\Events\InvalidEvent;
use Teksite\Handler\Tests\Fixtures\Models\User;
use Teksite\Handler\Tests\TestCase;
use Throwable;
use UnexpectedValueException;

class ServiceWrapperTest extends TestCase
{
    /**
     * @return array<string, array<int, Closure>>
     */
    public static function controlFlowExceptions(): array
    {
        return [
            'validation' => [fn () => ValidationException::withMessages(['name' => 'The name is required.'])],
            'authorization' => [fn () => new AuthorizationException('Denied.')],
            'authentication' => [fn () => new AuthenticationException()],
            'http exception' => [fn () => new NotFoundHttpException()],
            'http response' => [fn () => new HttpResponseException(new Response('stop', 400))],
        ];
    }

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
    public function a_caught_exception_is_logged_when_logging_is_enabled(): void
    {
        config()->set('handler.log', true);
        Log::spy();

        ServiceWrapper::make()
            ->do(fn () => throw new RuntimeException('boom'))
            ->run();

        Log::shouldHaveReceived('error')->once();
    }

    #[Test]
    public function nothing_is_logged_for_a_caught_exception_when_logging_is_disabled(): void
    {
        config()->set('handler.log', false);
        Log::spy();

        ServiceWrapper::make()
            ->do(fn () => throw new RuntimeException('boom'))
            ->run();

        Log::shouldNotHaveReceived('error');
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
    public function the_global_wrapper_switch_lets_exceptions_bubble_up(): void
    {
        config()->set('handler.wrapper', false);

        $this->expectException(RuntimeException::class);

        ServiceWrapper::make()
            ->do(fn () => throw new RuntimeException('boom'))
            ->run();
    }

    #[Test]
    public function a_disabled_handler_skips_the_if_failed_closure_and_the_failure_event(): void
    {
        Event::fake([OnFailureEvent::class]);
        $failureHandlerRan = false;
        $thrown = null;

        try {
            ServiceWrapper::make(withHandler: false)
                ->do(fn () => throw new RuntimeException('boom'))
                ->ifFailed(function () use (&$failureHandlerRan) {
                    $failureHandlerRan = true;
                })
                ->run(dispatchFailureEvent: true);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertFalse($failureHandlerRan);
        Event::assertNotDispatched(OnFailureEvent::class);
    }

    #[Test]
    public function a_disabled_handler_still_wraps_the_successful_result(): void
    {
        $result = ServiceWrapper::make(withHandler: false)
            ->do(fn () => 'value')
            ->run();

        $this->assertInstanceOf(ServiceResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame('value', $result->result);
    }

    #[Test]
    public function a_disabled_handler_returns_the_raw_value_when_wrapping_is_also_disabled(): void
    {
        $result = ServiceWrapper::make(withHandler: false, wrapServiceResult: false)
            ->do(fn () => 'raw-value')
            ->run();

        $this->assertSame('raw-value', $result);
    }

    #[Test]
    public function a_disabled_handler_still_runs_the_action_inside_a_transaction(): void
    {
        $thrown = null;

        try {
            ServiceWrapper::make(withHandler: false)
                ->do(function () {
                    User::create(['name' => 'Ada']);
                    throw new RuntimeException('boom after insert');
                })
                ->run();
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertSame(0, User::count());
    }

    #[Test]
    public function a_disabled_handler_dispatches_the_success_event_when_requested(): void
    {
        Event::fake([OnSuccessEvent::class]);

        ServiceWrapper::make(withHandler: false)
            ->do(fn () => 'payload')
            ->run(dispatchSuccessEvent: true);

        Event::assertDispatched(OnSuccessEvent::class, fn (OnSuccessEvent $event) => $event->result === 'payload');
    }

    #[Test]
    #[DataProvider('controlFlowExceptions')]
    public function control_flow_exceptions_are_rethrown_and_roll_back_the_transaction(Closure $makeException): void
    {
        config()->set('handler.log', true);
        Log::spy();
        Event::fake([OnFailureEvent::class]);

        $exception = $makeException();
        $failureHandlerRan = false;
        $thrown = null;

        try {
            ServiceWrapper::make()
                ->do(function () use ($exception) {
                    User::create(['name' => 'Ada']);
                    throw $exception;
                })
                ->ifFailed(function () use (&$failureHandlerRan) {
                    $failureHandlerRan = true;
                })
                ->run(dispatchFailureEvent: true);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertSame($exception, $thrown);
        $this->assertFalse($failureHandlerRan);
        $this->assertSame(0, User::count());
        Event::assertNotDispatched(OnFailureEvent::class);
        Log::shouldNotHaveReceived('error');
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
    public function the_failure_event_receives_the_event_data_too(): void
    {
        Event::fake([OnFailureEvent::class]);

        ServiceWrapper::make()
            ->withEventData(['actor' => 'system'])
            ->do(fn () => throw new RuntimeException('boom'))
            ->run(dispatchFailureEvent: true, additionalEventData: ['request_id' => 'abc-123']);

        Event::assertDispatched(OnFailureEvent::class, function (OnFailureEvent $event) {
            return $event->exception->getMessage() === 'boom'
                && $event->data['actor'] === 'system'
                && $event->data['request_id'] === 'abc-123';
        });
    }

    #[Test]
    public function a_custom_success_event_implementing_the_contract_is_dispatched(): void
    {
        config()->set('handler.success_event_class', CustomSuccessEvent::class);
        Event::fake([CustomSuccessEvent::class]);

        ServiceWrapper::make()
            ->withEventData(['actor' => 'system'])
            ->do(fn () => 'payload')
            ->run(dispatchSuccessEvent: true);

        Event::assertDispatched(CustomSuccessEvent::class, function (CustomSuccessEvent $event) {
            return $event->payload === 'payload' && $event->context === ['actor' => 'system'];
        });
    }

    #[Test]
    public function a_custom_failure_event_implementing_the_contract_is_dispatched(): void
    {
        config()->set('handler.failure_event_class', CustomFailureEvent::class);
        Event::fake([CustomFailureEvent::class]);

        ServiceWrapper::make()
            ->do(fn () => throw new RuntimeException('boom'))
            ->run(dispatchFailureEvent: true);

        Event::assertDispatched(
            CustomFailureEvent::class,
            fn (CustomFailureEvent $event) => $event->error->getMessage() === 'boom',
        );
    }

    #[Test]
    public function an_event_class_that_does_not_implement_the_contract_is_skipped_with_a_warning(): void
    {
        config()->set('handler.success_event_class', InvalidEvent::class);
        Event::fake([InvalidEvent::class]);
        Log::spy();

        $result = ServiceWrapper::make()
            ->do(fn () => 'payload')
            ->run(dispatchSuccessEvent: true);

        Event::assertNotDispatched(InvalidEvent::class);
        Log::shouldHaveReceived('warning')->once();
        $this->assertTrue($result->success);
        $this->assertSame('payload', $result->result);
    }

    #[Test]
    public function a_failure_while_dispatching_the_event_does_not_affect_the_service_result(): void
    {
        config()->set('handler.success_event_class', 'This\\Class\\Does\\Not\\Exist');
        Log::spy();

        $result = ServiceWrapper::make()
            ->do(fn () => 'payload')
            ->run(dispatchSuccessEvent: true);

        Log::shouldHaveReceived('warning')->once();
        $this->assertTrue($result->success);
        $this->assertSame('payload', $result->result);
    }

    #[Test]
    public function an_empty_event_class_disables_the_event_silently(): void
    {
        config()->set('handler.success_event_class', null);
        Log::spy();

        $result = ServiceWrapper::make()
            ->do(fn () => 'payload')
            ->run(dispatchSuccessEvent: true);

        Log::shouldNotHaveReceived('warning');
        $this->assertTrue($result->success);
    }

    #[Test]
    public function it_throws_when_the_configured_service_result_class_does_not_implement_the_contract(): void
    {
        config()->set('handler.service_result_class', \stdClass::class);

        $this->expectException(UnexpectedValueException::class);

        ServiceWrapper::make()->do(fn () => 'payload')->run();
    }

    #[Test]
    public function an_invalid_service_result_class_does_not_trigger_the_failure_path_after_a_successful_commit(): void
    {
        config()->set('handler.service_result_class', \stdClass::class);
        Event::fake([OnFailureEvent::class]);

        $failureHandlerRan = false;
        $thrown = null;

        try {
            ServiceWrapper::make()
                ->do(fn () => User::create(['name' => 'Ada']))
                ->ifFailed(function () use (&$failureHandlerRan) {
                    $failureHandlerRan = true;
                })
                ->run(dispatchFailureEvent: true);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(UnexpectedValueException::class, $thrown);
        $this->assertFalse($failureHandlerRan);
        Event::assertNotDispatched(OnFailureEvent::class);
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
