<?php

namespace Proclame\ClubPlanner\Tests;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Every operation in the spec, called through the SDK, has to arrive with the
 * right verb, the right path and its required query parameters.
 */
class OperationsTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string, string, string}>
     */
    public static function operations(): iterable
    {
        $spec = json_decode((string) file_get_contents(__DIR__.'/../spec/partner-api.json'), true);
        $map = (require __DIR__.'/../tools/operations.php')['map'];

        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $verb => $operation) {
                if (is_array($operation) && isset($map[$operation['operationId'] ?? ''])) {
                    yield $operation['operationId'] => [$operation, $verb, $path, ...$map[$operation['operationId']]];
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    #[DataProvider('operations')]
    public function test_the_operation_reaches_its_endpoint(array $operation, string $verb, string $path, string $class, string $method): void
    {
        $client = $this->clubPlanner([new Response(200, ['Content-Type' => 'image/png'], '[]')]);
        $resource = $client->{lcfirst($class)}();
        $reflection = new ReflectionMethod($resource, $method);

        $arguments = [];
        foreach ($reflection->getParameters() as $parameter) {
            if ($parameter->isOptional()) {
                continue;
            }
            $type = $parameter->getType();
            $arguments[] = match ($type instanceof ReflectionNamedType ? $type->getName() : 'string') {
                'int' => 7,
                'array' => ['note' => 'x'],
                default => 'abc',
            };
        }

        $reflection->invokeArgs($resource, $arguments);

        $this->assertSame(strtoupper($verb), $this->lastRequest()->getMethod());
        $this->assertSame(
            preg_replace_callback('/\{(\w+)\}/', fn (array $m): string => $m[1] === 'conversationId' ? 'abc' : '7', $path),
            $this->lastRequest()->getUri()->getPath(),
        );

        foreach ($operation['parameters'] ?? [] as $parameter) {
            if ($parameter['in'] === 'query' && ($parameter['required'] ?? false)) {
                $this->assertArrayHasKey($parameter['name'], $this->lastQuery());
            }
        }

        if ($operation['requestBody']['required'] ?? false) {
            $this->assertSame('{"note":"x"}', (string) $this->lastRequest()->getBody());
        }
    }

    public function test_every_operation_in_the_spec_is_covered(): void
    {
        $spec = json_decode((string) file_get_contents(__DIR__.'/../spec/partner-api.json'), true);

        $count = 0;
        foreach ($spec['paths'] as $operations) {
            foreach ($operations as $operation) {
                $count += is_array($operation) && isset($operation['operationId']) ? 1 : 0;
            }
        }

        // ping is hand-written on the ClubPlanner class.
        $this->assertSame($count - 1, iterator_count(self::operations()));
        $this->assertSame(199, $count);
    }

    public function test_the_generated_code_is_up_to_date(): void
    {
        $root = dirname(__DIR__);
        $output = sys_get_temp_dir().'/clubplanner-generated-'.uniqid();
        mkdir($output);

        exec('php '.escapeshellarg("{$root}/tools/generate.php").' '.escapeshellarg($output).' 2>&1', $lines, $exit);
        $this->assertSame(0, $exit, implode("\n", $lines));
        exec('cd '.escapeshellarg($root).' && vendor/bin/pint '.escapeshellarg($output).' --quiet 2>&1');

        $fresh = $this->resourceHashes($output);
        array_map('unlink', glob("{$output}/*.php"));
        rmdir($output);

        $this->assertSame($fresh, $this->resourceHashes("{$root}/src/Resources"), 'src/Resources differs from what the generator produces. Run composer generate.');
    }

    /**
     * @return array<string, string>
     */
    private function resourceHashes(string $directory): array
    {
        $hashes = [];
        foreach (glob("{$directory}/*.php") as $file) {
            $hashes[basename($file)] = md5_file($file);
        }

        return $hashes;
    }
}
