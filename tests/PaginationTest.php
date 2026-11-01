<?php

namespace Proclame\ClubPlanner\Tests;

use Proclame\ClubPlanner\ClubPlannerException;

class PaginationTest extends TestCase
{
    public function test_it_walks_every_page_until_an_empty_one(): void
    {
        $client = $this->clubPlanner([$this->json([['id' => 1], ['id' => 2]]), $this->json([['id' => 3]]), $this->json([])]);

        $ids = array_column(iterator_to_array($client->members()->iterate(filter: 'member_id>0', pageSize: 2), false), 'id');

        $this->assertSame([1, 2, 3], $ids);
        $this->assertCount(3, $this->history);
        $this->assertSame(['filter' => 'member_id>0', 'pageSize' => '2', 'pageNumber' => '3'], $this->lastQuery());
    }

    public function test_a_short_page_does_not_end_the_walk(): void
    {
        $client = $this->clubPlanner([$this->json([['id' => 1]]), $this->json([['id' => 2]]), $this->json([])]);

        $this->assertCount(2, iterator_to_array($client->leads()->iterate(pageSize: 50), false));
    }

    /**
     * /members takes pageNumber and the others take page. The wrong one is
     * silently ignored, so this is the trap to pin down per endpoint.
     */
    public function test_each_endpoint_gets_its_own_page_parameter(): void
    {
        $client = $this->clubPlanner([$this->json([])]);
        iterator_to_array($client->members()->iterate());
        $this->assertArrayHasKey('pageNumber', $this->lastQuery());

        foreach (['leads', 'actions', 'vouchers', 'bills'] as $resource) {
            $client = $this->clubPlanner([$this->json([])]);
            iterator_to_array($client->{$resource}()->iterate());
            $this->assertArrayHasKey('page', $this->lastQuery(), $resource);
        }
    }

    public function test_an_ignored_page_parameter_fails_rather_than_looping_forever(): void
    {
        $client = $this->clubPlanner([$this->json([['id' => 1]]), $this->json([['id' => 1]])]);

        $this->expectException(ClubPlannerException::class);
        $this->expectExceptionMessage('ignoring the page parameter');

        iterator_to_array($client->members()->iterate(), false);
    }

    public function test_nothing_is_fetched_until_it_is_consumed(): void
    {
        $this->clubPlanner([])->members()->iterate();

        $this->assertSame([], $this->history);
    }
}
