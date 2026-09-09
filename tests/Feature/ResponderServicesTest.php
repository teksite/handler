<?php

namespace Teksite\Handler\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use PHPUnit\Framework\Attributes\Test;
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
    public function go_redirects_back_when_no_url_was_set_and_flashes_the_reply_payload(): void
    {
        $this->startSession();

        $response = Responder::success('done')->go();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertTrue(session()->has('reply'));
        $this->assertSame(ResponseType::SUCCESS->value, session('reply')['type']);
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
    public function each_facade_call_resolves_a_fresh_stateless_builder_instance(): void
    {
        $first = app(ResponderServices::class)->message('one');
        $second = app(ResponderServices::class)->message('two');

        $this->assertNotSame($first, $second);
        $this->assertSame(['one'], $first->reply()->getData(true)['message']);
        $this->assertSame(['two'], $second->reply()->getData(true)['message']);
    }
}
