<?php

namespace Teksite\Handler\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Teksite\Handler\Contracts\ServiceResultContract;
use Teksite\Handler\Data\ServiceResult;
use Teksite\Handler\Enums\ResponseType;
use Teksite\Handler\Facade\Responder;
use Teksite\Handler\Services\Builder\ResponderServices;
use Teksite\Handler\Tests\TestCase;

class ResponderServicesTest extends TestCase
{
    #[Test]
    public function success_builds_a_json_response_with_the_expected_payload(): void
    {
        $response = Responder::success('Well done!', ['post' => ['id' => 1]], 201)->reply();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(201, $response->getStatusCode());

        $payload = $response->getData(true);

        $this->assertSame('Well done!', $payload['message'][0]);
        $this->assertSame(ResponseType::SUCCESS->value, $payload['type']);
        $this->assertSame(['id' => 1], $payload['data']['post']);
        $this->assertArrayNotHasKey('error', $payload);
    }

    #[Test]
    public function success_without_a_message_uses_the_translated_default(): void
    {
        $payload = Responder::success()->reply()->getData(true);

        $this->assertSame(['Successfully done'], $payload['message']);
    }

    #[Test]
    public function failed_builds_a_json_response_with_errors(): void
    {
        $response = Responder::failed('Something went wrong', ['auth' => 'forbidden'], 500)->reply();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(500, $response->getStatusCode());

        $payload = $response->getData(true);

        $this->assertSame('Something went wrong', $payload['message'][0]);
        $this->assertSame(ResponseType::FAILED->value, $payload['type']);
        $this->assertSame('forbidden', $payload['error']['auth']);
    }

    #[Test]
    public function failed_accepts_a_plain_string_as_the_error(): void
    {
        $payload = Responder::failed('Denied', 'forbidden', 403)->reply()->getData(true);

        $this->assertSame(['forbidden'], $payload['error']);
    }

    #[Test]
    public function failed_without_a_message_uses_the_translated_default(): void
    {
        $payload = Responder::failed()->reply()->getData(true);

        $this->assertSame(['Something went wrong'], $payload['message']);
        $this->assertSame(403, $payload['statusCode']);
    }

    #[Test]
    public function the_default_messages_can_be_overridden_through_the_translator(): void
    {
        app('translator')->addLines(['messages.success' => 'Done!'], 'en', 'handler');

        $payload = Responder::success()->reply()->getData(true);

        $this->assertSame(['Done!'], $payload['message']);
    }

    #[Test]
    public function an_invalid_status_code_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Responder::success('done', null, 700);
    }

    #[Test]
    public function go_redirects_back_when_no_url_was_set_and_flashes_the_reply_payload(): void
    {
        $this->startSession();

        $response = Responder::success('done')->go();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertTrue(session()->has('reply'));
        $this->assertSame(ResponseType::SUCCESS->value, session('reply')['type']);
    }

    #[Test]
    public function the_flashed_reply_never_contains_the_data(): void
    {
        $this->startSession();

        Responder::success('done', ['token' => 'secret'])->go();

        $this->assertArrayNotHasKey('data', session('reply'));
        $this->assertSame(['done'], session('reply')['message']);
    }

    #[Test]
    public function go_redirects_to_the_given_url(): void
    {
        $response = Responder::success('done')->go('https://example.test/thanks');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('https://example.test/thanks', $response->getTargetUrl());
    }

    #[Test]
    public function url_helper_sets_the_redirect_target(): void
    {
        $response = Responder::success('done')->url('https://example.test/there')->go();

        $this->assertSame('https://example.test/there', $response->getTargetUrl());
    }

    #[Test]
    public function repeated_message_calls_are_merged_into_a_single_message_list(): void
    {
        $payload = Responder::message('first')->message('second')->reply()->getData(true);

        $this->assertSame(['first', 'second'], $payload['message']);
    }

    #[Test]
    public function repeated_error_calls_are_merged(): void
    {
        $payload = Responder::error(['field_a' => 'required'])
            ->error(['field_b' => 'required'])
            ->reply()
            ->getData(true);

        $this->assertSame(
            ['field_a' => 'required', 'field_b' => 'required'],
            $payload['error']
        );
    }

    #[Test]
    public function from_result_builds_a_success_response_from_a_successful_service_result(): void
    {
        $serviceResult = new ServiceResult(true, ['id' => 5], successStatus: 201);

        $builder = Responder::fromResult($serviceResult);

        $this->assertInstanceOf(ResponderServices::class, $builder);

        $payload = $builder->reply()->getData(true);

        $this->assertSame(ResponseType::SUCCESS->value, $payload['type']);
        $this->assertSame(['id' => 5], $payload['data']);
        $this->assertSame(201, $payload['statusCode']);
        $this->assertSame(['Successfully done'], $payload['message']);
    }

    #[Test]
    public function from_result_builds_a_failed_response_from_a_failed_service_result(): void
    {
        $serviceResult = new ServiceResult(false, null, errors: ['server' => 'down'], failedStatus: 503);

        $payload = Responder::fromResult($serviceResult)->reply()->getData(true);

        $this->assertSame(ResponseType::FAILED->value, $payload['type']);
        $this->assertSame('down', $payload['error']['server']);
        $this->assertSame(503, $payload['statusCode']);
    }

    #[Test]
    public function from_result_uses_a_generic_translated_error_and_status_500_when_the_result_has_none(): void
    {
        $payload = Responder::fromResult(new ServiceResult(false))->reply()->getData(true);

        $this->assertSame(500, $payload['statusCode']);
        $this->assertSame('Something went wrong', $payload['error']['server']);
        $this->assertSame(['Something went wrong'], $payload['message']);
    }

    #[Test]
    public function from_result_accepts_a_string_error_from_the_service_result(): void
    {
        $payload = Responder::fromResult(new ServiceResult(false, null, 'broken'))->reply()->getData(true);

        $this->assertSame(['broken'], $payload['error']);
    }

    #[Test]
    public function from_result_uses_the_given_custom_messages(): void
    {
        $success = Responder::fromResult(new ServiceResult(true), success_message: 'Saved')->reply()->getData(true);
        $failure = Responder::fromResult(new ServiceResult(false), failed_message: 'Not saved')->reply()->getData(true);

        $this->assertSame(['Saved'], $success['message']);
        $this->assertSame(['Not saved'], $failure['message']);
    }

    #[Test]
    public function from_result_works_with_any_implementation_of_the_service_result_contract(): void
    {
        $custom = new class (true, ['id' => 9]) implements ServiceResultContract {
            public function __construct(private bool|null $success, private mixed $result) {}

            public function isSuccess(): bool
            {
                return $this->success;
            }

            public function isFailed(): bool
            {
                return !$this->success;
            }

            public function getResult(): mixed
            {
                return $this->result;
            }

            public function getErrors(): string|array|null
            {
                return null;
            }

            public function getSuccessStatus(): ?int
            {
                return 202;
            }

            public function getFailedStatus(): ?int
            {
                return null;
            }
        };

        $payload = Responder::fromResult($custom)->reply()->getData(true);

        $this->assertSame(['id' => 9], $payload['data']);
        $this->assertSame(202, $payload['statusCode']);
    }

    #[Test]
    public function from_result_with_auto_reply_returns_a_json_response_directly_when_no_url_is_set(): void
    {
        $response = Responder::fromResult(new ServiceResult(true, 'ok'), autoReply: true);

        $this->assertInstanceOf(JsonResponse::class, $response);
    }

    #[Test]
    public function from_result_with_auto_reply_redirects_when_a_success_url_is_given(): void
    {
        $response = Responder::fromResult(
            new ServiceResult(true, 'ok'),
            success_url: 'https://example.test/ok',
            autoReply: true,
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('https://example.test/ok', $response->getTargetUrl());
    }

    #[Test]
    public function from_result_with_auto_reply_redirects_to_the_failed_url_for_a_failed_result(): void
    {
        $response = Responder::fromResult(
            new ServiceResult(false),
            failed_url: 'https://example.test/failed',
            autoReply: true,
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('https://example.test/failed', $response->getTargetUrl());
    }

    #[Test]
    public function each_facade_call_resolves_a_fresh_stateless_builder_instance(): void
    {
        $first = app(ResponderServices::class)->message('one');
        $second = app(ResponderServices::class)->message('two');

        $this->assertNotSame($first, $second);
        $this->assertSame(['one'], $first->reply()->getData(true)['message']);
        $this->assertSame(['two'], $second->reply()->getData(true)['message']);
    }
}
