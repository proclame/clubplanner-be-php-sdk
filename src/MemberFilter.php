<?php

namespace Proclame\ClubPlanner;

use DateTimeInterface;

/**
 * Builds the filter expression GET /members and GET /members/count expect.
 *
 * The endpoints take a raw SQL-like fragment. Rather than hand-write that
 * string, pass structured values here and the expression is assembled with the
 * quoting done in one place.
 */
class MemberFilter
{
    /**
     * @var array<int, string>
     */
    protected array $conditions = [];

    public function __construct(protected ApiDate $dates = new ApiDate) {}

    public static function make(ApiDate $dates = new ApiDate): self
    {
        return new self($dates);
    }

    public function whereNameLike(?string $value): self
    {
        return $this->like('name', $value);
    }

    public function whereFirstNameLike(?string $value): self
    {
        return $this->like('firstname', $value);
    }

    public function whereEmailLike(?string $value): self
    {
        return $this->like('email_address', $value);
    }

    public function whereIdGreaterThan(?int $value): self
    {
        if ($value === null) {
            return $this;
        }

        $this->conditions[] = "member_id>{$value}";

        return $this;
    }

    public function whereCreatedAfter(DateTimeInterface|string|null $date): self
    {
        return $this->after('created_on', $date);
    }

    public function whereModifiedAfter(DateTimeInterface|string|null $date): self
    {
        return $this->after('last_modified', $date);
    }

    /**
     * Members linked to one parent, which is how Club Planner models a family
     * account: the children carry the parent's member id.
     *
     * The column is parent_member_id. The other spellings an API reader might
     * reach for (parentmemberid, parent_id) are rejected, and a rejected
     * column answers 200 with an HTML error page rather than an error.
     */
    public function whereParentMemberId(?int $value): self
    {
        if ($value === null) {
            return $this;
        }

        $this->conditions[] = "parent_member_id={$value}";

        return $this;
    }

    /**
     * Whether the member hangs off a parent at all: true for anyone on a
     * family account, false for the standalone members.
     */
    public function whereHasParent(?bool $value): self
    {
        if ($value === null) {
            return $this;
        }

        $this->conditions[] = $value ? 'parent_member_id>0' : 'parent_member_id is null';

        return $this;
    }

    public function whereNewsletter(?bool $value): self
    {
        if ($value === null) {
            return $this;
        }

        $this->conditions[] = 'newsletter='.($value ? '1' : '0');

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->conditions === [];
    }

    public function toString(): string
    {
        return implode(' and ', $this->conditions);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    protected function like(string $column, ?string $value): self
    {
        if ($value === null || trim($value) === '') {
            return $this;
        }

        $this->conditions[] = "{$column} like '%".$this->escape($value)."%'";

        return $this;
    }

    protected function after(string $column, DateTimeInterface|string|null $date): self
    {
        if ($date === null || (is_string($date) && trim($date) === '')) {
            return $this;
        }

        $this->conditions[] = "{$column}>'".$this->dates->filter($date)."'";

        return $this;
    }

    /**
     * Neutralise the characters that would otherwise break out of the quoted
     * literal or widen the match: single quotes are doubled, and LIKE
     * metacharacters are stripped so a caller's "%" cannot match everything.
     */
    protected function escape(string $value): string
    {
        return str_replace(["'", '%', '_', '['], ["''", '', '', ''], $value);
    }
}
