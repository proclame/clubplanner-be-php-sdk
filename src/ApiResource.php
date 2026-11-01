<?php

namespace Proclame\ClubPlanner;

use Closure;
use DateTimeInterface;
use Generator;

/**
 * Base for the generated classes in Resources/.
 */
abstract class ApiResource
{
    public function __construct(
        protected Transport $transport,
        protected ApiDate $dates,
    ) {}

    /**
     * @throws ClubPlannerException
     */
    protected function date(DateTimeInterface|string|null $value): ?string
    {
        return $this->isBlank($value) ? null : $this->dates->date($value);
    }

    /**
     * @throws ClubPlannerException
     */
    protected function dateTime(DateTimeInterface|string|null $value): ?string
    {
        return $this->isBlank($value) ? null : $this->dates->dateTime($value);
    }

    /**
     * Put the date fields of a request body onto the club's wall clock, at any
     * depth. Everything else, including an explicit null, is sent as given.
     *
     * @param  array<mixed>  $body
     * @param  array<int, string>  $dates  Keys documented as "format: date".
     * @param  array<int, string>  $dateTimes  Keys documented as "format: date-time".
     * @return array<mixed>
     *
     * @throws ClubPlannerException
     */
    protected function body(array $body, array $dates = [], array $dateTimes = []): array
    {
        foreach ($body as $key => $value) {
            $body[$key] = match (true) {
                is_array($value) => $this->body($value, $dates, $dateTimes),
                $this->isBlank($value), ! is_string($value) && ! $value instanceof DateTimeInterface => $value,
                in_array($key, $dates, strict: true) => $this->dates->date($value),
                in_array($key, $dateTimes, strict: true), $value instanceof DateTimeInterface => $this->dates->dateTime($value),
                default => $value,
            };
        }

        return $body;
    }

    /**
     * Walk a paged list, one row at a time, until a page comes back empty.
     *
     * Ends on an empty page rather than a short one: a page shorter than asked
     * for may just be the server capping pageSize, and stopping there would
     * silently truncate the result.
     *
     * @param  Closure(int): array<mixed>  $fetchPage
     * @return Generator<int, mixed>
     *
     * @throws ClubPlannerException
     */
    protected function paginate(Closure $fetchPage): Generator
    {
        $previous = null;

        for ($page = 1; ; $page++) {
            $rows = $fetchPage($page);

            if ($rows === []) {
                return;
            }

            // The page parameter is spelled differently per endpoint, and a
            // wrong spelling is ignored: page 1 would come back forever.
            if ($rows === $previous) {
                throw new ClubPlannerException("Page {$page} came back identical to the page before it, so the API is ignoring the page parameter.");
            }

            foreach ($rows as $row) {
                yield $row;
            }

            $previous = $rows;
        }
    }

    protected function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
