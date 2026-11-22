<?php

namespace Teksite\Handler\Tests\Unit\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Teksite\Handler\Enums\ResponseType;
use Teksite\Handler\Services\ResponderServices as ResponsePayload;
use Teksite\Handler\Tests\TestCase;

class ResponsePayloadTest extends TestCase
{
    /**
     * @return array<string, array<int, int>>
     */
    public static function invalidStatusCodes(): array
    {
        return [
            'too low' => [99],
            'zero' => [0],
            'negative' => [-1],
            'too high' => [600],
        ];
    }

    #[Test]
    public function an_empty_payload_only_contains_the_default_status_code(): void
    {
        $this->assertSame(['statusCode' => 200], (new ResponsePayload())->toArray());
    }

    #[Test]
    public function messages_accumulate_and_blank_values_are_dropped(): void
    {
        $payload = new ResponsePayload();

        $payload->setMessage('first');
        $payload->setMessage(['second', '', null]);
        $payload->setMessage('');
        $payload->setMessage(null);
        $payload->setMessage([]);

        $this->assertSame(['first', 'second'], $payload->toArray()['message']);
    }

    #[Test]
    public function errors_accumulate_and_empty_values_are_ignored(): void
    {
        $payload = new ResponsePayload();

        $payload->setError(['a' => 'one']);
        $payload->setError(['b' => 'two']);
        $payload->setError('');
        $payload->setError(null);

        $this->assertSame(['a' => 'one', 'b' => 'two'], $payload->toArray()['error']);
    }

    #[Test]
    public function array_data_is_merged_while_other_values_replace_the_previous_data(): void
    {
        $payload = new ResponsePayload();

        $payload->setData(['a' => 1]);
        $payload->setData(['b' => 2]);

        $this->assertSame(['a' => 1, 'b' => 2], $payload->toArray()['data']);

        $payload->setData('scalar');

        $this->assertSame('scalar', $payload->toArray()['data']);

        $payload->setData(null);
        $payload->setData([]);
        $payload->setData('');

        $this->assertSame('scalar', $payload->toArray()['data']);
    }

    #[Test]
    public function the_title_and_type_are_part_of_the_payload(): void
    {
        $payload = new ResponsePayload();

        $payload->setTitle('Saved');
        $payload->setType(ResponseType::WARNING);

        $this->assertSame('Saved', $payload->toArray()['title']);
        $this->assertSame('warning', $payload->toArray()['type']);
    }

    #[Test]
    public function a_valid_status_code_is_applied_and_null_keeps_the_current_one(): void
    {
        $payload = new ResponsePayload();

        $payload->setStatusCode(201);
        $payload->setStatusCode(null);

        $this->assertSame(201, $payload->toArray()['statusCode']);
    }

    #[Test]
    #[DataProvider('invalidStatusCodes')]
    public function an_invalid_status_code_is_rejected(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ResponsePayload())->setStatusCode($status);
    }

    #[Test]
    public function replying_builds_a_json_response_with_the_status_code(): void
    {
        $payload = new ResponsePayload();

        $payload->setStatusCode(422);
        $payload->setMessage('invalid');

        $response = $payload->replying();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['invalid'], $response->getData(true)['message']);
    }

    #[Test]
    public function redirecting_never_flashes_the_data_to_the_session(): void
    {
        $this->startSession();

        $payload = new ResponsePayload();

        $payload->setMessage('done');
        $payload->setData(['secret' => 'value']);
        $payload->setUrl('https://example.test/next');

        $response = $payload->redirecting();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('https://example.test/next', $response->getTargetUrl());
        $this->assertSame(['done'], session('reply')['message']);
        $this->assertArrayNotHasKey('data', session('reply'));
    }
}
