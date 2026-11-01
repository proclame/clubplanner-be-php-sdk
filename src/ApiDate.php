<?php

namespace Proclame\ClubPlanner;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Date formatting for the Partner API, which is ISO throughout: "format: date"
 * for a day and "format: date-time" for a moment.
 *
 * The API carries no offset in its date strings, so everything is converted
 * into the club's own timezone first. Without this an app running in UTC would
 * silently read and write the wrong hour.
 */
class ApiDate
{
    protected DateTimeZone $timezone;

    /**
     * @throws ClubPlannerException
     */
    public function __construct(string $timezone = 'Europe/Brussels')
    {
        try {
            $this->timezone = new DateTimeZone($timezone);
        } catch (\Exception $e) {
            throw new ClubPlannerException("[{$timezone}] is not a valid timezone.", previous: $e);
        }
    }

    /**
     * A plain calendar day: "2026-01-31".
     *
     * @throws ClubPlannerException
     */
    public function date(DateTimeInterface|string $date): string
    {
        return $this->inClubTimezone($date)->format('Y-m-d');
    }

    /**
     * A moment: "2026-01-31T12:30:00". Written without an offset, because the
     * API reads it as the club's wall clock.
     *
     * @throws ClubPlannerException
     */
    public function dateTime(DateTimeInterface|string $date): string
    {
        return $this->inClubTimezone($date)->format('Y-m-d\TH:i:s');
    }

    /**
     * Format a date for use inside a GET /members filter expression, whose raw
     * grammar wants it slashed: "created_on>'2026/01/31'".
     *
     * @throws ClubPlannerException
     */
    public function filter(DateTimeInterface|string $date): string
    {
        return $this->inClubTimezone($date)->format('Y/m/d');
    }

    /**
     * @throws ClubPlannerException
     */
    public function inClubTimezone(DateTimeInterface|string $date): DateTimeImmutable
    {
        return $this->parse($date)->setTimezone($this->timezone);
    }

    /**
     * Parse an incoming date. A value without an offset is read as the club's
     * local wall clock; a value with an offset keeps its own instant.
     *
     * @throws ClubPlannerException
     */
    public function parse(DateTimeInterface|string $date): DateTimeImmutable
    {
        if ($date instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($date);
        }

        $unreadable = "Could not read [{$date}] as a date. Use an ISO 8601 date such as 2026-01-31.";

        if (trim($date) === '') {
            throw new ClubPlannerException($unreadable);
        }

        try {
            $parsed = new DateTimeImmutable($date, $this->timezone);
        } catch (\Exception $e) {
            throw new ClubPlannerException($unreadable, previous: $e);
        }

        // PHP rolls 2026-02-31 over to March and only says so in a warning.
        if (DateTimeImmutable::getLastErrors() !== false) {
            throw new ClubPlannerException($unreadable);
        }

        return $parsed;
    }
}
