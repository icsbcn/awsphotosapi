<?php

namespace Tests\Unit;

use App\Support\DateParser;
use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DateParserTest extends TestCase
{
    public function test_parses_valid_european_date(): void
    {
        $date = DateParser::parseEuropean('31/01/2024');

        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertSame(2024, $date->year);
        $this->assertSame(1, $date->month);
        $this->assertSame(31, $date->day);
    }

    public function test_throws_for_iso_format(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/dd\/mm\/yyyy/');

        DateParser::parseEuropean('2024-01-31');
    }

    public function test_throws_for_missing_leading_zeros(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DateParser::parseEuropean('1/1/2024');
    }

    public function test_parses_valid_between_range(): void
    {
        $range = DateParser::parseBetween('01/01/2024,31/01/2024');

        $this->assertSame(1, $range['from']->day);
        $this->assertSame(31, $range['to']->day);
        $this->assertTrue($range['from']->isBefore($range['to']));
    }

    public function test_throws_when_between_has_invalid_separator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DateParser::parseBetween('01/01/2024 - 31/01/2024');
    }

    public function test_throws_when_start_date_is_after_end_date(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must not be after/');

        DateParser::parseBetween('31/01/2024,01/01/2024');
    }

    public function test_between_to_date_ends_at_end_of_day(): void
    {
        $range = DateParser::parseBetween('01/01/2024,01/01/2024');

        $this->assertSame(23, $range['to']->hour);
        $this->assertSame(59, $range['to']->minute);
    }
}
