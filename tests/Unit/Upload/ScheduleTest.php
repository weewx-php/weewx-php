<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Upload;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WeewxPhp\Upload\Schedule;

final class ScheduleTest extends TestCase
{
    private DateTimeZone $berlin;

    protected function setUp(): void
    {
        $this->berlin = new DateTimeZone('Europe/Berlin');
    }

    public function testSlotsSitOnTheHoursGrid(): void
    {
        $at = $this->at('14:23:10');
        self::assertSame($this->at('14:30:00'), Schedule::nextSlot($at, 600, $this->berlin));
        self::assertSame($this->at('14:25:00'), Schedule::nextSlot($at, 300, $this->berlin));
        self::assertSame($this->at('15:00:00'), Schedule::nextSlot($at, 3600, $this->berlin));
        self::assertSame($this->at('16:00:00'), Schedule::nextSlot($at, 7200, $this->berlin));
        // Exactly on a slot: the one after it.
        self::assertSame($this->at('14:40:00'), Schedule::nextSlot($this->at('14:30:00'), 600, $this->berlin));
    }

    public function testAnIntervalThatDoesNotDivideTheHourGetsAShortSlot(): void
    {
        self::assertSame($this->at('14:56:00'), Schedule::nextSlot($this->at('14:50:00'), 420, $this->berlin));
        self::assertSame($this->at('15:00:00'), Schedule::nextSlot($this->at('14:57:00'), 420, $this->berlin));
        self::assertSame($this->at('15:07:00'), Schedule::nextSlot($this->at('15:00:00'), 420, $this->berlin));
    }

    public function testTheGridIsTheLocalHour(): void
    {
        // Kathmandu is UTC+05:45: a two-hourly run lands on its own even hours.
        $kathmandu = new DateTimeZone('Asia/Kathmandu');
        $at = (new DateTimeImmutable('2026-08-26 13:10:00', $kathmandu))->getTimestamp();
        self::assertSame((new DateTimeImmutable('2026-08-26 15:00:00', $kathmandu))->getTimestamp(), Schedule::nextSlot($at, 7200, $kathmandu));
    }

    public function testDueOnceTheSlotAfterTheLastRunHasPassed(): void
    {
        $last = $this->at('14:23:10');
        self::assertTrue(Schedule::due($this->at('14:00:00'), null, 600, $this->berlin));
        self::assertFalse(Schedule::due($this->at('14:29:59'), $last, 600, $this->berlin));
        self::assertTrue(Schedule::due($this->at('14:30:00'), $last, 600, $this->berlin));
        self::assertTrue(Schedule::due($this->at('17:30:00'), $last, 600, $this->berlin));
    }

    private function at(string $time): int
    {
        return (new DateTimeImmutable('2026-08-26 ' . $time, $this->berlin))->getTimestamp();
    }
}
