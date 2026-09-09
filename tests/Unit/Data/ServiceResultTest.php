<?php

namespace Teksite\Handler\Tests\Unit\Data;

use Error;
use PHPUnit\Framework\Attributes\Test;
use Teksite\Handler\Contracts\ServiceResult as ServiceResultContract;
use Teksite\Handler\Data\ServiceResult;
use Teksite\Handler\Tests\TestCase;

class ServiceResultTest extends TestCase
{
    #[Test]
    public function it_implements_the_service_result_contract(): void
    {
        $result = new ServiceResult(true, 'payload');

        $this->assertInstanceOf(ServiceResultContract::class, $result);
    }

    #[Test]
    public function it_exposes_success_and_result_when_only_required_arguments_are_given(): void
    {
        $result = new ServiceResult(true, ['id' => 1]);

        $this->assertTrue($result->success);
        $this->assertSame(['id' => 1], $result->result);
        $this->assertNull($result->errors);
        $this->assertNull($result->successStatus);
        $this->assertNull($result->failedStatus);
    }

    #[Test]
    public function it_exposes_all_optional_arguments_when_provided(): void
    {
        $result = new ServiceResult(
            success: false,
            result: null,
            errors: ['field' => 'is required'],
            successStatus: 201,
            failedStatus: 422,
        );

        $this->assertFalse($result->success);
        $this->assertNull($result->result);
        $this->assertSame(['field' => 'is required'], $result->errors);
        $this->assertSame(201, $result->successStatus);
        $this->assertSame(422, $result->failedStatus);
    }

    #[Test]
    public function it_accepts_a_string_error_message_as_well_as_an_array(): void
    {
        $result = new ServiceResult(false, null, 'something went wrong');

        $this->assertSame('something went wrong', $result->errors);
    }

    #[Test]
    public function it_is_immutable(): void
    {
        $result = new ServiceResult(true, 'payload');

        $this->expectException(Error::class);

        // @phpstan-ignore-next-line - intentionally mutating a readonly property to assert immutability.
        $result->success = false;
    }
}
