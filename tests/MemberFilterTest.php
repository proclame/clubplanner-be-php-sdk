<?php

namespace Proclame\ClubPlanner\Tests;

use PHPUnit\Framework\TestCase;
use Proclame\ClubPlanner\MemberFilter;

class MemberFilterTest extends TestCase
{
    public function test_it_starts_empty(): void
    {
        $this->assertTrue(MemberFilter::make()->isEmpty());
        $this->assertSame('', MemberFilter::make()->toString());
    }

    public function test_it_builds_a_like_condition(): void
    {
        $this->assertSame(
            "name like '%verstr%'",
            MemberFilter::make()->whereNameLike('verstr')->toString(),
        );
    }

    public function test_it_joins_conditions_with_and(): void
    {
        $filter = MemberFilter::make()
            ->whereFirstNameLike('jan')
            ->whereNewsletter(true)
            ->toString();

        $this->assertSame("firstname like '%jan%' and newsletter=1", $filter);
    }

    public function test_it_formats_dates_year_first_and_quoted(): void
    {
        $this->assertSame(
            "created_on>'2026/01/31'",
            MemberFilter::make()->whereCreatedAfter('2026-01-31')->toString(),
        );
    }

    public function test_it_escapes_single_quotes(): void
    {
        $filter = MemberFilter::make()->whereNameLike("O'Brien")->toString();

        $this->assertSame("name like '%O''Brien%'", $filter);
    }

    public function test_it_strips_like_wildcards_from_input(): void
    {
        // A caller-supplied "%" would otherwise widen the match to everything.
        $filter = MemberFilter::make()->whereEmailLike('%@%')->toString();

        $this->assertSame("email_address like '%@%'", $filter);
    }

    public function test_an_injection_attempt_cannot_break_out_of_the_literal(): void
    {
        $filter = MemberFilter::make()->whereNameLike("x' or '1'='1")->toString();

        $this->assertSame("name like '%x'' or ''1''=''1%'", $filter);
        $this->assertSame(1, substr_count($filter, ' like '));
    }

    public function test_blank_values_add_nothing(): void
    {
        $filter = MemberFilter::make()
            ->whereNameLike(null)
            ->whereFirstNameLike('')
            ->whereEmailLike('   ')
            ->whereNewsletter(null)
            ->whereCreatedAfter(null)
            ->whereIdGreaterThan(null);

        $this->assertTrue($filter->isEmpty());
    }

    public function test_newsletter_false_is_kept(): void
    {
        $this->assertSame('newsletter=0', MemberFilter::make()->whereNewsletter(false)->toString());
    }

    /**
     * Club Planner models a family account by giving each member the parent's
     * id. The column is parent_member_id: parentmemberid, parent_id, parentid and
     * parent are all rejected, and a
     * rejected column answers 200 with an HTML error page rather than an
     * error, so getting this wrong would read as "nobody matched".
     */
    public function test_it_filters_on_the_parent_member(): void
    {
        $this->assertSame(
            'parent_member_id=11344',
            MemberFilter::make()->whereParentMemberId(11344)->toString(),
        );
    }

    public function test_it_finds_members_on_a_family_account(): void
    {
        $this->assertSame(
            'parent_member_id>0',
            MemberFilter::make()->whereHasParent(true)->toString(),
        );
    }

    /**
     * A standalone member has no parent at all, which is null rather than 0.
     */
    public function test_it_finds_standalone_members(): void
    {
        $this->assertSame(
            'parent_member_id is null',
            MemberFilter::make()->whereHasParent(false)->toString(),
        );
    }

    public function test_the_parent_filters_are_skipped_when_not_given(): void
    {
        $filter = MemberFilter::make()->whereParentMemberId(null)->whereHasParent(null);

        $this->assertTrue($filter->isEmpty());
    }

    public function test_the_parent_filter_combines_with_the_others(): void
    {
        $filter = MemberFilter::make()
            ->whereParentMemberId(11344)
            ->whereNewsletter(true);

        $this->assertSame('parent_member_id=11344 and newsletter=1', $filter->toString());
    }
}
