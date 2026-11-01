<?php

namespace Proclame\ClubPlanner\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Proclame\ClubPlanner\ClubPlanner;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends BaseTestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    protected array $history = [];

    /**
     * A client answering from the given responses, in order. No test can reach
     * a real platform: anything not queued here throws.
     *
     * @param  array<int, Response>  $responses
     * @param  array<string, mixed>  $options
     */
    protected function clubPlanner(array $responses, array $options = []): ClubPlanner
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new ClubPlanner(...[
            'baseUrl' => 'https://club.invalid',
            'apiKey' => 'cp_testkey',
            'http' => new Client(['handler' => $stack]),
            ...$options,
        ]);
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data));
    }

    protected function lastRequest(): RequestInterface
    {
        return $this->history[array_key_last($this->history)]['request'];
    }

    /** @return array<string, string> */
    protected function lastQuery(): array
    {
        parse_str($this->lastRequest()->getUri()->getQuery(), $query);

        return $query;
    }
}
