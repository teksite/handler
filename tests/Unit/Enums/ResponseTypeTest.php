<?php

namespace Teksite\Handler\Tests\Unit\Enums;

use PHPUnit\Framework\Attributes\Test;
use Teksite\Handler\Enums\ResponseType;
use Teksite\Handler\Tests\TestCase;

class ResponseTypeTest extends TestCase
{
    #[Test]
    public function it_has_the_five_expected_cases(): void
    {
        $this->assertCount(5, ResponseType::cases());

        $this->assertSame(
            ['success', 'failed', 'error', 'warning', 'info'],
            array_map(fn (ResponseType $case) => $case->value, ResponseType::cases())
        );
    }

    #[Test]
    public function it_can_be_resolved_from_its_backing_value(): void
    {
        $this->assertSame(ResponseType::SUCCESS, ResponseType::from('success'));
        $this->assertSame(ResponseType::FAILED, ResponseType::from('failed'));
        $this->assertSame(ResponseType::Error, ResponseType::from('error'));
        $this->assertSame(ResponseType::WARNING, ResponseType::from('warning'));
        $this->assertSame(ResponseType::INFO, ResponseType::from('info'));
    }

    #[Test]
    public function resolving_an_unknown_value_throws(): void
    {
        $this->expectException(\ValueError::class);

        ResponseType::from('not-a-real-type');
    }
}
