<?php

namespace Proclame\ClubPlanner\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Proclame\ClubPlanner\ClubPlanner;
use Proclame\ClubPlanner\ClubPlannerException;
use Proclame\ClubPlanner\Redactor;

class TransportTest extends TestCase
{
    public function test_it_builds_the_versioned_partner_path(): void
    {
        $this->clubPlanner([$this->json([])], ['baseUrl' => 'https://club.invalid/'])->transport()->get('members/1');

        $this->assertSame('https://club.invalid/api/partner/v1/members/1', (string) $this->lastRequest()->getUri());
    }

    public function test_the_version_is_configurable(): void
    {
        $this->clubPlanner([$this->json([])], ['version' => 'v2'])->transport()->get('club');

        $this->assertStringContainsString('/api/partner/v2/club', (string) $this->lastRequest()->getUri());
    }

    public function test_the_key_travels_in_the_header_and_not_the_query(): void
    {
        $this->clubPlanner([$this->json([])])->clubs()->list();

        $this->assertSame('cp_testkey', $this->lastRequest()->getHeaderLine('X-API-Key'));
        $this->assertStringNotContainsString('cp_testkey', (string) $this->lastRequest()->getUri());
    }

    public function test_a_missing_key_fails_before_any_request(): void
    {
        $client = $this->clubPlanner([], ['apiKey' => null]);

        try {
            $client->clubs()->list();
            $this->fail('Expected an exception.');
        } catch (ClubPlannerException $e) {
            $this->assertNull($e->status);
            $this->assertSame([], $this->history);
        }
    }

    public function test_ping_needs_no_key(): void
    {
        $this->assertSame(['status' => 'ok'], $this->clubPlanner([$this->json(['status' => 'ok'])], ['apiKey' => null])->ping());
        $this->assertFalse($this->lastRequest()->hasHeader('X-API-Key'));
    }

    public function test_responses_are_returned_whole_unless_redaction_is_asked_for(): void
    {
        $payload = ['id' => 1, 'bankAccount' => 'BE00', 'nested' => [['Password' => 'x', 'keep' => 1]]];

        $this->assertSame($payload, $this->clubPlanner([$this->json($payload)])->members()->get(1));

        $redacted = $this->clubPlanner([$this->json($payload)], ['redact' => Redactor::SENSITIVE_FIELDS])->members()->get(1);

        $this->assertSame(['id' => 1, 'nested' => [['keep' => 1]]], $redacted);
    }

    public function test_query_drops_nulls_and_blanks_but_keeps_false_and_zero(): void
    {
        $this->clubPlanner([$this->json([])])->sales()->list(clubId: 0, itemName: '  ', revenueOnly: false);

        $this->assertSame(['clubId' => '0', 'revenueOnly' => 'false'], $this->lastQuery());
    }

    public function test_query_booleans_are_words_not_digits(): void
    {
        $this->clubPlanner([$this->json([])])->sales()->list(revenueOnly: true);

        $this->assertSame('revenueOnly=true', $this->lastRequest()->getUri()->getQuery());
    }

    public function test_query_values_are_encoded(): void
    {
        $this->clubPlanner([$this->json([])])->members()->list(filter: "email_address like '%a&b%'");

        $this->assertSame(['filter' => "email_address like '%a&b%'"], $this->lastQuery());
    }

    public function test_member_id_zero_is_a_real_id(): void
    {
        $this->clubPlanner([$this->json(['id' => 0])])->members()->get(0);

        $this->assertStringEndsWith('/members/0', $this->lastRequest()->getUri()->getPath());
    }

    public function test_a_body_is_json_and_an_empty_one_is_an_object(): void
    {
        $client = $this->clubPlanner([$this->json([]), $this->json([])]);

        $client->sales()->update(5, ['note' => null, 'quantity' => 0]);
        $this->assertSame('{"note":null,"quantity":0}', (string) $this->lastRequest()->getBody());
        $this->assertSame('application/json', $this->lastRequest()->getHeaderLine('Content-Type'));

        $client->vouchers()->redeem(5);
        $this->assertSame('{}', (string) $this->lastRequest()->getBody());
    }

    public function test_a_write_without_a_request_body_sends_none(): void
    {
        $this->clubPlanner([$this->json([])])->leads()->stepBack(5);

        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('', (string) $this->lastRequest()->getBody());
    }

    public function test_query_parameters_on_a_write_are_kept(): void
    {
        $this->clubPlanner([$this->json([])])->invoices()->markPaid(5, payMethodId: 3);

        $this->assertSame(['payMethodId' => '3'], $this->lastQuery());
    }

    public function test_an_empty_answer_to_a_write_is_null_not_a_failure(): void
    {
        $this->assertNull($this->clubPlanner([new Response(204)])->members()->delete(5));
    }

    public function test_a_read_that_is_not_json_is_a_failure(): void
    {
        $this->expectException(ClubPlannerException::class);
        $this->expectExceptionMessage('non-JSON');

        $this->clubPlanner([new Response(200, [], 'nope')])->clubs()->list();
    }

    public function test_an_html_page_behind_a_200_is_a_failure(): void
    {
        try {
            $this->clubPlanner([new Response(200, ['Content-Type' => 'text/html'], '<html><h2>Cannot open database</h2></html>')])
                ->members()->list(filter: 'nope=1');
            $this->fail('Expected an exception.');
        } catch (ClubPlannerException $e) {
            $this->assertStringContainsString('Cannot open database', $e->getMessage());
            $this->assertSame(200, $e->status);
        }
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function failures(): array
    {
        return [
            '401' => [401, 'isUnauthorized', 'rejected the key'],
            '403' => [403, 'isForbidden', 'lacks read or write access'],
            '404' => [404, 'isNotFound', 'error (404)'],
            '409' => [409, 'isConflict', 'error (409)'],
            '429' => [429, 'isRateLimited', 'No call was made'],
        ];
    }

    #[DataProvider('failures')]
    public function test_failures_carry_their_status_and_message(int $status, string $predicate, string $text): void
    {
        try {
            $this->clubPlanner([$this->json(['status' => $status, 'message' => 'From the API.'], $status)])->clubs()->list();
            $this->fail('Expected an exception.');
        } catch (ClubPlannerException $e) {
            $this->assertSame($status, $e->status);
            $this->assertTrue($e->{$predicate}());
            $this->assertStringContainsString('From the API.', $e->getMessage());
            $this->assertStringContainsString($text, $e->getMessage());
        }
    }

    public function test_an_unstructured_error_body_is_truncated_into_the_message(): void
    {
        try {
            $this->clubPlanner([new Response(500, [], str_repeat('x', 1000))])->clubs()->list();
            $this->fail('Expected an exception.');
        } catch (ClubPlannerException $e) {
            $this->assertLessThan(400, strlen($e->getMessage()));
        }
    }

    public function test_a_connection_failure_is_wrapped_without_the_key(): void
    {
        $handler = new MockHandler([new ConnectException('Connection refused', new Request('GET', 'x'))]);
        $client = new ClubPlanner('https://club.invalid', 'cp_testkey', http: new Client(['handler' => $handler]));

        try {
            $client->clubs()->list();
            $this->fail('Expected an exception.');
        } catch (ClubPlannerException $e) {
            $this->assertNull($e->status);
            $this->assertStringContainsString('Connection refused', $e->getMessage());
            $this->assertStringNotContainsString('cp_testkey', $e->getMessage());
        }
    }

    public function test_a_file_comes_back_as_bytes_with_its_type(): void
    {
        $file = $this->clubPlanner([new Response(200, ['Content-Type' => 'application/pdf; charset=x'], '%PDF')])->invoices()->pdf(5);

        $this->assertSame('%PDF', $file->bytes);
        $this->assertSame('application/pdf', $file->mimeType);
        $this->assertSame(4, $file->size);
    }

    public function test_a_file_can_be_saved(): void
    {
        $file = $this->clubPlanner([new Response(200, ['Content-Type' => 'image/png'], 'png')])->clubs()->logo(1);
        $path = tempnam(sys_get_temp_dir(), 'cp');
        $file->saveTo($path);

        $this->assertSame('png', file_get_contents($path));
        unlink($path);
    }

    public function test_json_on_a_file_endpoint_is_not_a_file(): void
    {
        $this->expectException(ClubPlannerException::class);
        $this->expectExceptionMessage('no file');

        $this->clubPlanner([$this->json(['message' => 'none'])])->clubs()->logo(1);
    }

    public function test_a_failure_on_a_file_endpoint_carries_its_status(): void
    {
        try {
            $this->clubPlanner([$this->json(['message' => 'gone'], 404)])->clubs()->logo(1);
            $this->fail('Expected an exception.');
        } catch (ClubPlannerException $e) {
            $this->assertTrue($e->isNotFound());
        }
    }
}
