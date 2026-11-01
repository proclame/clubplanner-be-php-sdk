<?php

namespace Proclame\ClubPlanner;

use DateTimeInterface;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use stdClass;

/**
 * HTTP layer for the Club Planner Partner API (/api/partner/{version}).
 *
 * Authenticates with an X-API-Key header and uses real verbs with JSON bodies.
 * Failures raise a ClubPlannerException carrying the HTTP status, because the
 * status says things the message does not: a 403 is the key lacking access and
 * a 429 means the call never ran.
 *
 * Public so an endpoint this SDK does not cover yet can still be called:
 * $clubPlanner->transport()->get('some/new/endpoint').
 */
class Transport
{
    protected string $baseUrl;

    protected ClientInterface $http;

    public function __construct(
        string $baseUrl,
        protected ?string $apiKey,
        protected ApiDate $dates,
        protected string $version = 'v1',
        protected int|float $timeout = 15,
        protected ?Redactor $redactor = null,
        ?ClientInterface $http = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = $http ?? new Client;
    }

    /**
     * Read a collection or an object.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     *
     * @throws ClubPlannerException
     */
    public function get(string $path, array $query = []): array
    {
        $decoded = $this->value($path, $query);

        if (! is_array($decoded)) {
            throw new ClubPlannerException(
                "The Club Planner API returned a non-JSON response for [GET {$path}]."
            );
        }

        return $decoded;
    }

    /**
     * Read an endpoint whose answer may be a bare scalar rather than an object
     * or a list.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws ClubPlannerException
     */
    public function value(string $path, array $query = []): mixed
    {
        return $this->send('GET', $path, $query);
    }

    /**
     * Create something. The body is sent as JSON.
     *
     * A write often answers with an empty body, so a null return means the
     * call succeeded and said nothing - not that it failed. A failure always
     * raises a ClubPlannerException.
     *
     * @param  array<mixed>|null  $body  Null sends no body at all.
     * @param  array<string, mixed>  $query
     *
     * @throws ClubPlannerException
     */
    public function post(string $path, ?array $body = null, array $query = []): mixed
    {
        return $this->send('POST', $path, $query, $body);
    }

    /**
     * Replace a record. Note that some endpoints documented as PUT take the
     * whole object rather than a patch: PUT /leads/{id} is a replace, so a
     * caller has to read the lead first and merge.
     *
     * @param  array<mixed>|null  $body
     * @param  array<string, mixed>  $query
     *
     * @throws ClubPlannerException
     */
    public function put(string $path, ?array $body = null, array $query = []): mixed
    {
        return $this->send('PUT', $path, $query, $body);
    }

    /**
     * Change only the fields given.
     *
     * @param  array<mixed>|null  $body
     * @param  array<string, mixed>  $query
     *
     * @throws ClubPlannerException
     */
    public function patch(string $path, ?array $body = null, array $query = []): mixed
    {
        return $this->send('PATCH', $path, $query, $body);
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws ClubPlannerException
     */
    public function delete(string $path, array $query = []): mixed
    {
        return $this->send('DELETE', $path, $query);
    }

    /**
     * Fetch a file: an image or a PDF rather than JSON. The Redactor does not
     * apply, as there is nothing to walk in a binary body.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws ClubPlannerException
     */
    public function file(string $path, array $query = []): BinaryFile
    {
        $response = $this->request('GET', $path, $query, accept: '*/*');
        $status = $response->getStatusCode();

        if ($status >= 400) {
            throw new ClubPlannerException($this->errorMessage($response, 'GET', $path), status: $status);
        }

        $mimeType = trim(explode(';', $response->getHeaderLine('Content-Type'))[0]);
        $bytes = (string) $response->getBody();

        // A JSON or HTML body on a file endpoint is the API reporting a problem
        // in the only way it can, not a file.
        if ($bytes === '' || str_contains($mimeType, 'json') || str_contains($mimeType, 'html')) {
            throw new ClubPlannerException(
                "Club Planner API returned no file for [GET {$path}]".
                ($mimeType === '' ? '.' : " (it answered with {$mimeType}).").
                ' The record may have no image or document attached.',
                status: $status,
            );
        }

        return new BinaryFile($bytes, $mimeType);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<mixed>|null  $body
     *
     * @throws ClubPlannerException
     */
    protected function send(string $method, string $path, array $query = [], ?array $body = null): mixed
    {
        $response = $this->request($method, $path, $query, $body);
        $status = $response->getStatusCode();

        if ($status >= 400) {
            throw new ClubPlannerException($this->errorMessage($response, $method, $path), status: $status);
        }

        $this->guardAgainstHtml($response, $method, $path);

        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) && $this->redactor !== null
            ? $this->redactor->redact($decoded)
            : $decoded;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<mixed>|null  $body
     *
     * @throws ClubPlannerException
     */
    protected function request(string $method, string $path, array $query = [], ?array $body = null, string $accept = 'application/json'): ResponseInterface
    {
        $headers = ['Accept' => $accept];

        if ($this->apiKey !== null && trim($this->apiKey) !== '') {
            $headers['X-API-Key'] = $this->apiKey;
        } elseif ($path !== 'ping') {
            // /ping is the one endpoint that answers without a key.
            throw new ClubPlannerException('No Club Planner API key configured.');
        }

        $options = [
            RequestOptions::HEADERS => $headers,
            RequestOptions::TIMEOUT => $this->timeout,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::QUERY => $this->queryString($query),
        ];

        if ($body !== null) {
            // An empty PHP array would encode as a JSON list.
            $options[RequestOptions::JSON] = $body === [] ? new stdClass : $body;
        }

        try {
            return $this->http->request($method, $this->url($path), $options);
        } catch (GuzzleException $e) {
            throw new ClubPlannerException(
                "Could not reach the Club Planner API at {$this->baseUrl}: {$e->getMessage()}",
                previous: $e,
            );
        }
    }

    /**
     * A 200 does not always mean it worked.
     *
     * GET /members with an unknown filter column answers 200 with an HTML
     * error page ("Cannot open database ..."), not a 400. Left alone that
     * decodes to null and surfaces as an empty result, which reads as "no
     * members match" rather than "your filter is wrong".
     *
     * @throws ClubPlannerException
     */
    protected function guardAgainstHtml(ResponseInterface $response, string $method, string $path): void
    {
        $body = ltrim((string) $response->getBody());

        if ($body === '' || ! str_starts_with($body, '<')) {
            return;
        }

        $detail = preg_match('/<h2>(.*?)<\/h2>/is', $body, $m) === 1
            ? trim(strip_tags($m[1]))
            : 'the response was an HTML page rather than JSON';

        throw new ClubPlannerException(
            "Club Planner API answered [{$method} {$path}] with an error page despite a ".
            "{$response->getStatusCode()}: {$detail}. On a filtered read this usually means an ".
            'unknown column in the filter expression.',
            status: $response->getStatusCode(),
        );
    }

    protected function url(string $path): string
    {
        return $this->baseUrl.'/api/partner/'.$this->version.'/'.ltrim($path, '/');
    }

    /**
     * Turn a failure into something that can be reported as-is.
     *
     * Errors are JSON - {"status":403,"message":"..."} - and the status carries
     * meaning the message does not always spell out.
     */
    protected function errorMessage(ResponseInterface $response, string $method, string $path): string
    {
        $where = "[{$method} {$path}]";
        $body = trim((string) $response->getBody());
        $json = json_decode($body, true);
        $message = is_array($json) ? ($json['message'] ?? $json['Message'] ?? null) : null;

        if (! is_string($message) || trim($message) === '') {
            $message = $body === '' ? 'empty response body' : mb_strimwidth($body, 0, 300, '...');
        }

        return match ($response->getStatusCode()) {
            401 => "Club Planner API rejected the key on {$where}: {$message}",
            403 => "Club Planner API denied access on {$where}: {$message} ".
                '(the key lacks read or write access to this namespace, or is limited to another club).',
            429 => "Club Planner API rate limit reached on {$where}: {$message} ".
                'No call was made. Wait for the key\'s period to reset rather than retrying immediately.',
            default => "Club Planner API error ({$response->getStatusCode()}) on {$where}: {$message}",
        };
    }

    /**
     * Drop the parameters nobody filled in, so an optional filter never reaches
     * the API as an empty string. False and 0 are real values and are sent:
     * member id 0 exists, and a flag such as activeOnly=false has to arrive.
     *
     * Built by hand because http_build_query() turns booleans into 1 and 0.
     *
     * @param  array<string, mixed>  $query
     */
    protected function queryString(array $query): string
    {
        $pairs = [];

        foreach ($query as $key => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $item = match (true) {
                    is_bool($item) => $item ? 'true' : 'false',
                    $item instanceof DateTimeInterface => $this->dates->dateTime($item),
                    default => $item,
                };

                if ($item === null || (is_string($item) && trim($item) === '')) {
                    continue;
                }

                $pairs[] = rawurlencode($key).'='.rawurlencode((string) $item);
            }
        }

        return implode('&', $pairs);
    }
}
