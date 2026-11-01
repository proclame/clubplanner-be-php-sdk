<?php

/**
 * Generates src/Resources from spec/partner-api.json and tools/operations.php.
 *
 * To follow a change in the API: replace the spec with a fresh copy of
 * https://<platform>/openapi/partner.json, run `composer generate`, add any
 * operation it names to operations.php, then run the tests.
 */
$root = dirname(__DIR__);
$spec = json_decode((string) file_get_contents("{$root}/spec/partner-api.json"), true, flags: JSON_THROW_ON_ERROR);
$config = require __DIR__.'/operations.php';
$prefix = '/api/partner/v1/';

function deref(array $schema, array $spec): array
{
    if (isset($schema['$ref'])) {
        return deref($spec['components']['schemas'][basename($schema['$ref'])], $spec);
    }

    if (isset($schema['allOf'])) {
        $merged = array_diff_key($schema, ['allOf' => true]);
        foreach ($schema['allOf'] as $part) {
            $part = deref($part, $spec);
            $merged['properties'] = ($merged['properties'] ?? []) + ($part['properties'] ?? []);
            $merged['required'] = [...($merged['required'] ?? []), ...($part['required'] ?? [])];
            $merged += $part;
        }

        return $merged;
    }

    return $schema;
}

/** A phpdoc type for a request body schema, expanded while it fits on the stack. */
function docType(array $schema, array $spec, string $indent, int $depth = 0): string
{
    $nullable = $schema['nullable'] ?? false;
    $schema = deref($schema, $spec);
    $nullable = $nullable || ($schema['nullable'] ?? false);

    $type = match ($schema['type'] ?? 'object') {
        'integer' => 'int',
        'number' => 'float',
        'boolean' => 'bool',
        'string' => match (true) {
            isset($schema['enum']) => implode('|', array_map(fn ($v) => "'{$v}'", $schema['enum'])),
            in_array($schema['format'] ?? '', ['date', 'date-time'], true) => '\DateTimeInterface|string',
            default => 'string',
        },
        'array' => 'list<'.docType($schema['items'] ?? [], $spec, $indent, $depth).'>',
        default => objectType($schema, $spec, $indent, $depth),
    };

    return $nullable ? "{$type}|null" : $type;
}

function objectType(array $schema, array $spec, string $indent, int $depth): string
{
    if (empty($schema['properties']) || $depth >= 3) {
        return 'array<string, mixed>';
    }

    $required = $schema['required'] ?? [];
    $lines = [];

    foreach ($schema['properties'] as $name => $property) {
        $lines[] = "{$indent}    {$name}".(in_array($name, $required, true) ? '' : '?').': '.docType($property, $spec, "{$indent}    ", $depth + 1);
    }

    return "array{\n".implode(",\n", $lines)."\n{$indent}}";
}

/** Every key documented as a date or a date-time, at any depth. */
function dateKeys(array $schema, array $spec, array &$dates, array &$dateTimes, array $seen = []): void
{
    if (isset($schema['$ref'])) {
        if (in_array($schema['$ref'], $seen, true)) {
            return;
        }
        $seen[] = $schema['$ref'];
    }

    $schema = deref($schema, $spec);

    if (isset($schema['items'])) {
        dateKeys($schema['items'], $spec, $dates, $dateTimes, $seen);
    }

    foreach ($schema['properties'] ?? [] as $name => $property) {
        $format = deref($property, $spec)['format'] ?? null;
        if ($format === 'date') {
            $dates[$name] = $name;
        } elseif ($format === 'date-time') {
            $dateTimes[$name] = $name;
        }
        dateKeys($property, $spec, $dates, $dateTimes, $seen);
    }
}

function wrap(string $text, string $linePrefix = '     * '): array
{
    $text = trim(str_replace('*/', '* /', $text));
    $out = [];
    foreach (preg_split('/\R/', $text) as $line) {
        foreach (explode("\n", wordwrap($line, 88, "\n", false)) as $piece) {
            $out[] = rtrim($linePrefix.$piece);
        }
    }

    return $out;
}

function phpList(array $values): string
{
    return "['".implode("', '", array_values($values))."']";
}

function bodyFieldNotes(array $schema, array $spec): array
{
    $lines = [];
    foreach (deref($schema, $spec)['properties'] ?? [] as $name => $property) {
        $description = $property['description'] ?? deref($property, $spec)['description'] ?? null;
        if (is_string($description) && $description !== '') {
            $lines = [...$lines, ...wrap("- {$name}: {$description}")];
        }
    }

    return $lines;
}

/** @return array{0: list<string>, 1: list<string>} */
function renderOperation(array $op, string $method, string $path, array $config, array $spec, string $prefix): array
{
    $id = $op['operationId'];
    [, $name] = $config['map'][$id];
    $relative = substr($path, strlen($prefix));
    $params = array_map(fn ($p) => isset($p['$ref']) ? $spec['components']['parameters'][basename($p['$ref'])] : $p, $op['parameters'] ?? []);

    $pathParams = array_values(array_filter($params, fn ($p) => $p['in'] === 'path'));
    $queryParams = array_values(array_filter($params, fn ($p) => $p['in'] === 'query'));
    $required = array_values(array_filter($queryParams, fn ($p) => $p['required'] ?? false));
    $optional = array_values(array_filter($queryParams, fn ($p) => ! ($p['required'] ?? false)));

    $bodySchema = $op['requestBody']['content']['application/json']['schema'] ?? null;
    $bodyRequired = $op['requestBody']['required'] ?? false;

    $phpType = function (array $param) {
        return match (true) {
            ($param['schema']['type'] ?? '') === 'integer' => 'int',
            ($param['schema']['type'] ?? '') === 'number' => 'float',
            ($param['schema']['type'] ?? '') === 'boolean' => 'bool',
            in_array($param['schema']['format'] ?? '', ['date', 'date-time'], true) => 'DateTimeInterface|string',
            default => 'string',
        };
    };
    $nullableType = fn (string $type) => str_contains($type, '|') ? "{$type}|null" : "?{$type}";

    // Response.
    $responses = array_filter($op['responses'], fn ($code) => ((string) $code)[0] === '2', ARRAY_FILTER_USE_KEY);
    $response = reset($responses) ?: [];
    $contentType = array_key_first($response['content'] ?? []);
    $responseSchema = $contentType !== null ? ($response['content'][$contentType]['schema'] ?? []) : [];
    $responseName = isset($responseSchema['items']['$ref'])
        ? basename($responseSchema['items']['$ref']).'[]'
        : (isset($responseSchema['$ref']) ? basename($responseSchema['$ref']) : null);

    $isFile = $contentType !== null && $contentType !== 'application/json';
    $isList = ($responseSchema['type'] ?? null) === 'array';
    $isRead = $method === 'get';
    $isScalarRead = in_array($id, $config['scalar_reads'], true);

    [$returnType, $docReturn] = match (true) {
        $isFile => ['BinaryFile', null],
        $isRead && $isScalarRead => ['mixed', null],
        $isRead && $isList => ['array', isset($responseSchema['items']['$ref']) ? 'list<array<string, mixed>>' : 'list<mixed>'],
        $isRead => ['array', 'array<string, mixed>'],
        default => ['mixed', null],
    };

    // Signature.
    $signature = [];
    $doc = [];
    foreach ([...$pathParams, ...$required] as $param) {
        $signature[] = $phpType($param).' $'.$param['name'];
    }
    if ($bodySchema !== null) {
        $signature[] = 'array $body'.($bodyRequired ? '' : ' = []');
    }
    foreach ($optional as $param) {
        $signature[] = $nullableType($phpType($param)).' $'.$param['name'].' = null';
    }

    foreach ($params as $param) {
        $type = $phpType($param);
        if (! ($param['required'] ?? false)) {
            $type = $nullableType($type);
        }
        if (isset($param['schema']['enum'])) {
            $type = implode('|', array_map(fn ($v) => "'{$v}'", $param['schema']['enum'])).(($param['required'] ?? false) ? '' : '|null');
        }
        $description = trim((string) ($param['description'] ?? ''));
        $doc[] = "     * @param  {$type}  \${$param['name']}".($description !== '' ? '  '.preg_replace('/\s+/', ' ', str_replace('*/', '* /', $description)) : '');
    }

    $dates = $dateTimes = [];
    if ($bodySchema !== null) {
        dateKeys($bodySchema, $spec, $dates, $dateTimes);
        $doc[] = '     * @param  '.docType($bodySchema, $spec, '     * ').'  $body';
    }

    // Body of the method.
    $pathExpr = "'".$relative."'";
    if ($pathParams !== []) {
        $interpolated = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($pathParams) {
            foreach ($pathParams as $p) {
                if ($p['name'] === $m[1] && ($p['schema']['type'] ?? '') !== 'integer') {
                    return "'.rawurlencode(\${$m[1]}).'";
                }
            }

            return '{$'.$m[1].'}';
        }, $relative);
        $pathExpr = str_contains($interpolated, "'.rawurlencode")
            ? "'".$interpolated."'"
            : '"'.$interpolated.'"';
        $pathExpr = preg_replace("/\\.''$/", '', $pathExpr);
    }

    $queryLines = function (array $list) use ($phpType): string {
        $items = [];
        foreach ($list as $param) {
            $var = '$'.$param['name'];
            if (str_contains($phpType($param), 'DateTimeInterface')) {
                $helper = ($param['schema']['format'] ?? '') === 'date' ? 'date' : 'dateTime';
                $var = "\$this->{$helper}({$var})";
            }
            $items[] = "            '{$param['name']}' => {$var},";
        }

        return $items === [] ? '' : "[\n".implode("\n", $items)."\n        ]";
    };
    $query = $queryLines($queryParams);

    $bodyExpr = 'null';
    if ($bodySchema !== null) {
        $extra = '';
        if ($dates !== []) {
            $extra .= ', dates: '.phpList($dates);
        }
        if ($dateTimes !== []) {
            $extra .= ', dateTimes: '.phpList($dateTimes);
        }
        $bodyExpr = "\$this->body(\$body{$extra})";
    }

    $call = match (true) {
        $isFile => '$this->transport->file('.$pathExpr.($query !== '' ? ", {$query}" : '').')',
        $isRead => '$this->transport->'.($isScalarRead ? 'value' : 'get').'('.$pathExpr.($query !== '' ? ", {$query}" : '').')',
        $method === 'delete' => '$this->transport->delete('.$pathExpr.($query !== '' ? ", {$query}" : '').')',
        default => '$this->transport->'.$method.'('.$pathExpr.($bodyExpr !== 'null' || $query !== '' ? ", {$bodyExpr}" : '').($query !== '' ? ", {$query}" : '').')',
    };

    // Docblock.
    $block = ['    /**'];
    $block = [...$block, ...wrap((string) ($op['summary'] ?? $id).'.')];
    if (! empty($op['description'])) {
        $block[] = '     *';
        $block = [...$block, ...wrap($op['description'])];
    }
    if (isset($config['notes'][$id])) {
        $block[] = '     *';
        $block = [...$block, ...wrap($config['notes'][$id])];
    }
    if ($bodySchema !== null && ($fieldNotes = bodyFieldNotes($bodySchema, $spec)) !== []) {
        $block[] = '     *';
        $block[] = '     * Body fields:';
        $block = [...$block, ...$fieldNotes];
    }
    $block[] = '     *';
    $block[] = '     * '.strtoupper($method)." /{$relative}".($responseName !== null ? " responds with {$responseName}." : '.');
    $block[] = '     *';
    $block = [...$block, ...$doc];
    if ($docReturn !== null) {
        $block[] = "     * @return {$docReturn}";
    }
    if ($doc !== [] || $docReturn !== null) {
        $block[] = '     *';
    }
    $block[] = '     * @throws ClubPlannerException';
    $block[] = '     */';

    $lines = [...$block, '    public function '.$name.'('.implode(', ', $signature)."): {$returnType}", '    {', "        return {$call};", '    }'];

    // iterate().
    if (isset($config['paged'][$id])) {
        $pageParam = $config['paged'][$id];
        $filters = array_values(array_filter($optional, fn ($p) => ! in_array($p['name'], ['pageSize', $pageParam], true)));
        $iterateSignature = array_map(fn ($p) => $nullableType($phpType($p)).' $'.$p['name'].' = null', $filters);
        $iterateSignature[] = 'int $pageSize = 100';
        $arguments = array_map(fn ($p) => '$'.$p['name'], $filters);
        $arguments[] = 'pageSize: $pageSize';
        $arguments[] = "{$pageParam}: \$page";

        $iterateDoc = [];
        foreach ($filters as $param) {
            $type = isset($param['schema']['enum'])
                ? implode('|', array_map(fn ($v) => "'{$v}'", $param['schema']['enum'])).'|null'
                : $nullableType($phpType($param));
            $iterateDoc[] = "     * @param  {$type}  \${$param['name']}";
        }

        $lines = [...$lines, '', '    /**',
            '     * Every row of '.$name.'(), fetching page after page as it is consumed. Ends with one',
            '     * extra request, for the empty page that says there is no more.',
            '     *',
            ...$iterateDoc,
            '     * @return Generator<int, array<string, mixed>>',
            '     *',
            '     * @throws ClubPlannerException',
            '     */',
            '    public function iterate('.implode(', ', $iterateSignature).'): Generator',
            '    {',
            '        /** @var Generator<int, array<string, mixed>> */',
            '        return $this->paginate(fn (int $page): array => $this->'.$name.'('.implode(', ', $arguments).'));',
            '    }',
        ];
    }

    return [$config['map'][$id][0], $lines];
}

$seen = [];
$classes = [];
foreach ($spec['paths'] as $path => $operations) {
    foreach ($operations as $method => $op) {
        if (! is_array($op) || ! isset($op['operationId'])) {
            continue;
        }
        $id = $op['operationId'];
        $seen[] = $id;
        if ($id === 'ping') {
            continue; // Hand-written on the ClubPlanner class.
        }
        if (! isset($config['map'][$id])) {
            fwrite(STDERR, "Operation [{$id}] ({$method} {$path}) is not mapped in tools/operations.php.\n");
            exit(1);
        }
        [$class, $lines] = renderOperation($op, $method, $path, $config, $spec, $prefix);
        $classes[$class][$config['map'][$id][1]] = $lines;
    }
}

if ($stale = array_diff(array_keys($config['map']), $seen)) {
    fwrite(STDERR, 'Mapped but no longer in the spec: '.implode(', ', $stale)."\n");
    exit(1);
}

$output = $argv[1] ?? "{$root}/src/Resources";
array_map('unlink', glob("{$output}/*.php"));

foreach ($classes as $class => $methods) {
    $flat = array_merge(...array_map(fn ($lines) => [...$lines, ''], array_values($methods)));
    array_pop($flat);
    $source = implode("\n", $flat);

    $imports = ['use Proclame\ClubPlanner\ClubPlannerException;', 'use Proclame\ClubPlanner\ApiResource;'];
    if (str_contains($source, 'BinaryFile')) {
        $imports[] = 'use Proclame\ClubPlanner\BinaryFile;';
    }
    if (preg_match('/^\s*public function .*DateTimeInterface/m', $source)) {
        $imports[] = 'use DateTimeInterface;';
    }
    if (str_contains($source, 'Generator')) {
        $imports[] = 'use Generator;';
    }
    sort($imports);

    file_put_contents("{$output}/{$class}.php", "<?php\n\nnamespace Proclame\\ClubPlanner\\Resources;\n\n".implode("\n", $imports)."\n\n/**\n * Generated by tools/generate.php. Do not edit: change the generator or operations.php.\n */\nclass {$class} extends ApiResource\n{\n{$source}\n}\n");
}

echo count($seen).' operations, '.count($classes)." resource classes.\n";
