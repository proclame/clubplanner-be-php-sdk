<?php

namespace Proclame\ClubPlanner\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use Proclame\ClubPlanner\ApiDate;
use Proclame\ClubPlanner\ClubPlannerException;

class DatesTest extends TestCase
{
    public function test_a_moment_is_moved_onto_the_club_wall_clock(): void
    {
        $utc = new DateTimeImmutable('2026-07-01 22:30:00', new DateTimeZone('UTC'));

        $this->assertSame('2026-07-02T00:30:00', (new ApiDate)->dateTime($utc));
        $this->assertSame('2026-07-02', (new ApiDate)->date($utc));
        $this->assertSame('2026/07/02', (new ApiDate)->filter($utc));
    }

    public function test_a_naive_string_already_means_the_club_wall_clock(): void
    {
        $this->assertSame('2026-01-31T13:42:00', (new ApiDate)->dateTime('2026-01-31 13:42'));
    }

    public function test_a_string_with_an_offset_keeps_its_instant(): void
    {
        $this->assertSame('2026-01-31T13:00:00', (new ApiDate)->dateTime('2026-01-31T12:00:00Z'));
    }

    public function test_the_timezone_is_configurable(): void
    {
        $this->assertSame('2026-01-31T07:00:00', (new ApiDate('America/New_York'))->dateTime('2026-01-31T12:00:00Z'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unreadable(): array
    {
        return ['garbage' => ['not a date'], 'empty' => ['  '], 'rolls over' => ['2026-02-31']];
    }

    #[DataProvider('unreadable')]
    public function test_an_unreadable_date_is_refused(string $value): void
    {
        $this->expectException(ClubPlannerException::class);

        (new ApiDate)->date($value);
    }

    public function test_an_invalid_timezone_is_refused(): void
    {
        $this->expectException(ClubPlannerException::class);

        new ApiDate('Mars/Olympus');
    }

    public function test_query_dates_use_the_format_of_the_parameter(): void
    {
        $utc = new DateTimeImmutable('2026-07-01 22:30:00', new DateTimeZone('UTC'));

        $this->clubPlanner([$this->json([])])->sales()->list(from: $utc);
        $this->assertSame(['from' => '2026-07-02'], $this->lastQuery());

        $this->clubPlanner([$this->json([])])->visits()->list(from: $utc);
        $this->assertSame(['from' => '2026-07-02T00:30:00'], $this->lastQuery());
    }

    public function test_body_dates_use_the_format_of_the_field(): void
    {
        $utc = new DateTimeImmutable('2026-07-01 22:30:00', new DateTimeZone('UTC'));

        $this->clubPlanner([$this->json([])])->sales()->add([
            'itemName' => 'x', 'totalAmount' => 1.0, 'revenueGroupId' => 1, 'payMethodId' => 1, 'vat' => 21.0,
            'createdOn' => $utc,
        ]);
        $this->assertSame('2026-07-02T00:30:00', json_decode((string) $this->lastRequest()->getBody(), true)['createdOn']);

        $this->clubPlanner([$this->json([])])->members()->create(['lastName' => 'x', 'birthDate' => $utc, 'note' => null]);
        $body = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertSame('2026-07-02', $body['birthDate']);
        $this->assertArrayHasKey('note', $body);
    }
}
