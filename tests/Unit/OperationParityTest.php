<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\Operations\ImageWatermarkMetadata;
use Gisl\Sdk\Ergonomic\MergeOptions;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\FilesRecipe;
use Gisl\Sdk\FileFirst\Recipe;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * tfdUA2Sl (DX A1b-PHP) — the `php` column of docs/parity/operations.yaml,
 * proven on the real PHP surface rather than read from the code by hand. The
 * TypeScript twin is packages/typescript/tests/unit/operation-parity.test.ts
 * (xybG2CzQ).
 *
 * For every REQUIRED row (availability `stable` or `beta`; planned rows are
 * listed, not required) and every REACHABLE `php` entry on it (a file-first
 * step, a client method, or `{generic: operation}`; `gap` and `excluded` are
 * skipped):
 *  - (1) the method is public and DECLARED on the class a caller reaches:
 *    {@see Recipe} for `via: file`, {@see FilesRecipe} for `via: files`, and
 *    {@see GislErgonomicClient} for a client method and for `operation`, so a
 *    method inherited from {@see GislClient} cannot satisfy a verb row;
 *  - (2) the method, called BY THE YAML NAME on a real GislErgonomicClient over
 *    a throw-on-exhaustion PSR-18 stub and ended with the builder's
 *    no-argument `submit()`, sends exactly N uploads of the given stub files, in
 *    order, then ONE workflow-create whose only non-`passthrough` operation is
 *    the row's operation (the passthrough row: exactly one bare passthrough),
 *    built over the file ids the uploads returned.
 *
 * Upload type: a file-first input is a `FileInput::resource()` carrying the
 * group's FIRST MIME, and its multipart part must carry exactly that type. A
 * client verb takes only a path and uploads `application/octet-stream`
 * (GislClient::buildSingleShotMultipartBody), so those cases assert that and
 * the filename, whose extension is the group's. Group-null rows skip the type.
 *
 * Inputs are a few stub bytes in temp files, never media (owner decision 839).
 *
 * ⚠️ BOUND: this proves the SDK SENDS the row's operation for the group's FIRST
 * MIME. It does not prove the server accepts it, nor that every MIME of the
 * group routes the same way.
 *
 * @phpstan-type Entry array<string, string>
 * @phpstan-type Row array{operation: string, group: ?string, entries: list<Entry>}
 * @phpstan-type Stub array{path: string, name: string, bytes: string, type: ?string}
 */
#[CoversClass(GislErgonomicClient::class)]
final class OperationParityTest extends TestCase
{
    /** The contract's `sdk_exposure: expose` rungs: a row on one of them is required (YAML header). */
    private const REQUIRED_AVAILABILITY = ['stable', 'beta'];

    /** Required rows when this test was written; fewer means rows were dropped, not proven. */
    private const MIN_REQUIRED_ROWS = 38;

    /** The source op merge / archive / watermark lower each upload through; not the row's operation. */
    private const PASSTHROUGH = 'passthrough';

    private const WORKFLOW_ID = '01936fb2-0000-7000-8000-0000000a1b0c';

    /** Upload i returns FILE_ID_PREFIX . i (i < 10). */
    private const FILE_ID_PREFIX = '01936fb1-0000-7000-8000-0000000a1b0';

    /** The extension each group's first MIME is written with; a new first MIME must be added, never skipped. */
    private const EXTENSIONS = [
        'application/epub+zip' => 'epub',
        'application/pdf' => 'pdf',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'audio/mp4' => 'm4a',
        'audio/mpeg' => 'mp3',
        'image/avif' => 'avif',
        'image/bmp' => 'bmp',
        'image/gif' => 'gif',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/svg+xml' => 'svg',
        'image/tiff' => 'tiff',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
    ];

    /**
     * Split's required key differs per group and GIF `frame_range` has no contract default, so the
     * values are literals. Each literal `mode` is checked against the group's shippable `mode.values`
     * in availability.json (splitOptions), so a contract change to the modes goes red here.
     */
    private const SPLIT_OPTIONS = [
        'image_gif' => ['frame_range' => '1-10'],
        'document_pdf' => ['mode' => 'page_range', 'page_range' => '1-2'],
        'audio' => ['mode' => 'interval', 'interval' => 10],
        'video' => ['mode' => 'interval', 'interval' => 10],
    ];

    /** @var list<Row>|null */
    private static ?array $requiredRows = null;

    /** @var array<string, mixed>|null */
    private static ?array $availability = null;

    /** @var list<string> */
    private array $tempFiles = [];

    /** @var list<resource> */
    private array $streams = [];

    protected function tearDown(): void
    {
        foreach ($this->streams as $stream) {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }
        foreach ($this->tempFiles as $path) {
            @\unlink($path);
        }
        $this->streams = [];
        $this->tempFiles = [];
    }

    // ------------------------------------------------------------------
    // YAML and availability.json
    // ------------------------------------------------------------------

    /** @return list<Row> */
    private static function requiredRows(): array
    {
        if (self::$requiredRows === null) {
            $path = \dirname(__DIR__, 4) . '/docs/parity/operations.yaml';
            $parsed = Yaml::parseFile($path);
            if (!\is_array($parsed) || !\array_is_list($parsed)) {
                throw new \RuntimeException("{$path}: top level must be a list of rows");
            }
            $rows = [];
            foreach ($parsed as $raw) {
                if (
                    !\is_array($raw)
                    || !\is_string($raw['operation'] ?? null)
                    || !\is_string($raw['availability'] ?? null)
                    || !\array_key_exists('group', $raw)
                    || !($raw['group'] === null || \is_string($raw['group']))
                ) {
                    throw new \RuntimeException("{$path}: every row needs a string operation and availability, and a group");
                }
                if (!\in_array($raw['availability'], self::REQUIRED_AVAILABILITY, true)) {
                    continue;
                }
                $id = self::rowId($raw);
                $php = $raw['php'] ?? null;
                if (!\is_array($php)) {
                    throw new \RuntimeException("{$id}: a required row needs a php mapping");
                }
                $entries = \array_values(\array_filter(
                    [self::fileFirstEntry($php['file_first'] ?? null, $id), self::clientEntry($php['client'] ?? null, $id)],
                    static fn (?array $entry): bool => $entry !== null,
                ));
                $rows[] = ['operation' => $raw['operation'], 'group' => $raw['group'], 'entries' => $entries];
            }
            self::$requiredRows = $rows;
        }

        return self::$requiredRows;
    }

    /**
     * `{via, step}` is reachable; `{gap}` / `{excluded}` is not (null). Anything else is a YAML error.
     *
     * @return Entry|null
     */
    private static function fileFirstEntry(mixed $value, string $id): ?array
    {
        if (\is_array($value)) {
            $keys = \array_keys($value);
            \sort($keys);
            if ($keys === ['step', 'via'] && \in_array($value['via'], ['file', 'files'], true) && self::isNonEmptyString($value['step'])) {
                return ['kind' => 'file_first', 'via' => $value['via'], 'step' => $value['step']];
            }
            if (($keys === ['gap'] || $keys === ['excluded']) && self::isNonEmptyString($value[$keys[0]])) {
                return null;
            }
        }
        throw new \RuntimeException("{$id}: php.file_first must be {via: file|files, step}, {gap: card} or {excluded: reason}");
    }

    /**
     * A method name or `{generic: operation}` is reachable; `{gap}` is not (null).
     *
     * @return Entry|null
     */
    private static function clientEntry(mixed $value, string $id): ?array
    {
        if (self::isNonEmptyString($value)) {
            return ['kind' => 'client', 'method' => $value];
        }
        if (\is_array($value) && \count($value) === 1) {
            if (($value['generic'] ?? null) === 'operation') {
                return ['kind' => 'generic'];
            }
            if (self::isNonEmptyString($value['gap'] ?? null)) {
                return null;
            }
        }
        throw new \RuntimeException("{$id}: php.client must be a method name, {generic: operation} or {gap: card}");
    }

    /** @phpstan-assert-if-true non-empty-string $value */
    private static function isNonEmptyString(mixed $value): bool
    {
        return \is_string($value) && $value !== '';
    }

    /** @param Row $row */
    private static function rowId(array $row): string
    {
        return $row['operation'] . '/' . ($row['group'] ?? 'null');
    }

    /**
     * @param Row $row
     * @return array<string, mixed>
     */
    private static function mimeGroup(array $row): array
    {
        if (self::$availability === null) {
            // operations/src/ImageWatermarkMetadata.php -> dirname x3 = generated/php root,
            // as WatermarkCapabilityConformanceTest locates it.
            $root = \dirname((string) (new \ReflectionClass(ImageWatermarkMetadata::class))->getFileName(), 3);
            $json = \json_decode((string) \file_get_contents($root . '/availability/availability.json'), true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($json) || !\is_array($json['operations'] ?? null)) {
                throw new \RuntimeException('availability.json has no operations object');
            }
            self::$availability = $json['operations'];
        }
        $group = $row['group'] === null ? null : (self::$availability[$row['operation']]['mime_groups'][$row['group']] ?? null);
        if (!\is_array($group)) {
            throw new \RuntimeException(self::rowId($row) . ': no such mime_group in availability.json');
        }

        return $group;
    }

    /**
     * The MIME every stub of a grouped row carries: the group's first. Null for an ungrouped row.
     *
     * @param Row $row
     */
    private static function groupMime(array $row): ?string
    {
        if ($row['group'] === null) {
            return null;
        }
        $first = self::mimeGroup($row)['mimes'][0] ?? null;
        if (!\is_string($first)) {
            throw new \RuntimeException(self::rowId($row) . ': mime_group lists no mimes');
        }

        return $first;
    }

    /**
     * The first value of an enum option that is not `planned`: one a caller can actually send.
     *
     * @param Row $row
     */
    private static function firstShippableValue(array $row, string $option): string
    {
        $spec = self::mimeGroup($row)['options'][$option] ?? [];
        foreach ($spec['values'] ?? [] as $value) {
            if (($spec['per_value_availability'][$value]['availability'] ?? null) !== 'planned') {
                return $value;
            }
        }
        throw new \RuntimeException(self::rowId($row) . ": {$option} has no non-planned value in availability.json");
    }

    /** @return Row */
    private static function requiredRow(string $operation, string $group): array
    {
        foreach (self::requiredRows() as $row) {
            if ($row['operation'] === $operation && $row['group'] === $group) {
                return $row;
            }
        }
        throw new \RuntimeException("No required row {$operation}/{$group}");
    }

    /** @param Entry $entry */
    private static function entryLabel(array $entry): string
    {
        return match ($entry['kind']) {
            'file_first' => ($entry['via'] === 'file' ? 'file(x)' : 'files([a, b])') . '->' . $entry['step'],
            'client' => 'client->' . $entry['method'],
            default => 'client->operation',
        };
    }

    /**
     * One case per (required row, reachable entry).
     *
     * @return array<string, array{Row, Entry}>
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (self::requiredRows() as $row) {
            foreach ($row['entries'] as $entry) {
                $cases[self::rowId($row) . ' ' . self::entryLabel($entry)] = [$row, $entry];
            }
        }

        return $cases;
    }

    public function testEveryRequiredRowHasAReachablePhpEntryAndEveryEntryKindIsExercised(): void
    {
        self::assertGreaterThanOrEqual(self::MIN_REQUIRED_ROWS, \count(self::requiredRows()));
        self::assertSame([], \array_values(\array_map(
            self::rowId(...),
            \array_filter(self::requiredRows(), static fn (array $row): bool => $row['entries'] === []),
        )));
        $kinds = \array_map(static fn (array $case): string => $case[1]['kind'], \array_values(self::cases()));
        foreach (['file_first', 'client', 'generic'] as $kind) {
            self::assertContains($kind, $kinds, "no case exercises a {$kind} entry");
        }
    }

    // ------------------------------------------------------------------
    // (1) Existence on the class a caller reaches
    // ------------------------------------------------------------------

    /**
     * @param Entry $entry
     * @return array{class-string, string}
     */
    private static function memberOf(array $entry): array
    {
        return match ($entry['kind']) {
            'file_first' => [$entry['via'] === 'file' ? Recipe::class : FilesRecipe::class, $entry['step']],
            'client' => [GislErgonomicClient::class, $entry['method']],
            default => [GislErgonomicClient::class, 'operation'],
        };
    }

    /**
     * Null when $class has a public method $method declared on $class itself, else why not.
     *
     * @param class-string $class
     */
    private static function existenceFailure(string $class, string $method): ?string
    {
        $reflection = new \ReflectionClass($class);
        if (!$reflection->hasMethod($method)) {
            return "{$class} has no method {$method}";
        }
        $declared = $reflection->getMethod($method);
        // PHP resolves method names case-insensitively, so hasMethod('textwatermark')
        // is true; the YAML name must be the declared spelling.
        if ($declared->getName() !== $method) {
            return "{$class} has no method {$method} (only {$declared->getName()})";
        }
        if (!$declared->isPublic()) {
            return "{$class}::{$method} is not public";
        }
        $declaringClass = $declared->getDeclaringClass()->getName();

        return $declaringClass === $class ? null : "{$class}::{$method} is declared on {$declaringClass}";
    }

    public function testEveryMemberARequiredRowNamesIsPublicAndDeclaredOnTheClassItReaches(): void
    {
        $failures = [];
        foreach (self::cases() as [, $entry]) {
            [$class, $method] = self::memberOf($entry);
            $failures[] = self::existenceFailure($class, $method);
        }

        self::assertSame([], \array_values(\array_unique(\array_filter($failures, static fn (?string $f): bool => $f !== null))));
    }

    // ------------------------------------------------------------------
    // (2) Binding: call by the YAML name, capture the uploads and the create
    // ------------------------------------------------------------------

    /**
     * The stub files the call is given, in the order the SDK uploads them (base
     * before overlay). `type` is the multipart Content-Type the upload must carry,
     * or null where the row has no group to check it against.
     *
     * @param Row $row
     * @param Entry $entry
     * @return list<Stub>
     */
    private function stubsFor(array $row, array $entry): array
    {
        $mime = self::groupMime($row);
        $extension = $mime === null ? 'bin' : (self::EXTENSIONS[$mime] ?? null);
        if ($extension === null) {
            throw new \RuntimeException(self::rowId($row) . ": no extension for {$mime}; add it to EXTENSIONS, never skip the row");
        }
        $fileFirst = $entry['kind'] === 'file_first';
        // Only a file-first FileInput::resource can carry a MIME; a path upload is octet-stream.
        $type = $mime === null ? null : ($fileFirst ? $mime : 'application/octet-stream');
        $count = ($fileFirst && $entry['via'] === 'files') || ($entry['kind'] === 'client' && $entry['method'] === 'merge') ? 2 : 1;

        $stubs = [];
        for ($i = 0; $i < $count; $i++) {
            $stubs[] = $this->stub($i, $extension, $type);
        }
        if ($fileFirst && $entry['step'] === 'watermark') {
            // Any image overlay; only the base carries the group's MIME.
            $stubs[] = $this->stub($count, 'png', 'image/png');
        }

        return $stubs;
    }

    /**
     * @param Row $row
     * @return array<string, mixed>
     */
    private static function splitOptions(array $row): array
    {
        $options = $row['group'] === null ? null : (self::SPLIT_OPTIONS[$row['group']] ?? null);
        if ($options === null) {
            throw new \RuntimeException(self::rowId($row) . ': no split options for this group; add them, never skip the row');
        }
        if (isset($options['mode'])) {
            $modes = self::mimeGroup($row)['options']['mode'] ?? [];
            $shippable = \in_array($options['mode'], $modes['values'] ?? [], true)
                && ($modes['per_value_availability'][$options['mode']]['availability'] ?? null) !== 'planned';
            if (!$shippable) {
                throw new \RuntimeException(self::rowId($row) . ": split mode '{$options['mode']}' is not a shippable mode.values entry in availability.json; update SPLIT_OPTIONS");
            }
        }

        return $options;
    }

    /**
     * Image merge has no `output_type` default and is refused locally without one.
     *
     * @param Row $row
     */
    private static function mergeOptions(array $row): MergeOptions
    {
        return new MergeOptions(output: self::firstShippableValue($row, 'output_type'));
    }

    /**
     * The object the YAML-named method is called on, and its arguments (options LAST).
     *
     * @param Row $row
     * @param Entry $entry
     * @param list<Stub> $stubs
     * @return array{object, string, list<mixed>}
     */
    private function invocation(GislErgonomicClient $client, array $row, array $entry, array $stubs): array
    {
        $paths = \array_column($stubs, 'path');
        if ($entry['kind'] === 'generic') {
            return [$client, 'operation', [$row['operation'], $paths[0]]];
        }
        if ($entry['kind'] === 'client') {
            $method = $entry['method'];
            $args = match ($method) {
                'compress', 'thumbnail' => [$paths[0]],
                'convert' => [$paths[0], self::firstShippableValue($row, 'output_format')],
                'split' => [$paths[0], self::splitOptions($row)],
                'merge' => [$paths, self::mergeOptions($row)],
                default => throw new \RuntimeException(self::rowId($row) . ": no arguments for client->{$method}; add them, never skip the row"),
            };

            return [$client, $method, $args];
        }

        $inputs = \array_map($this->resourceInput(...), $stubs);
        $step = $entry['step'];
        $args = match ($step) {
            'compress', 'thumbnail', 'archive' => [],
            'convert' => [self::firstShippableValue($row, 'output_format')],
            'textWatermark' => ['GISL'],
            'split' => [self::splitOptions($row)],
            'merge' => [self::mergeOptions($row)],
            'watermark' => [$client->file($inputs[1])],
            default => throw new \RuntimeException(self::rowId($row) . ": no arguments for file-first step {$step}; add them, never skip the row"),
        };

        return [$entry['via'] === 'files' ? $client->files($inputs) : $client->file($inputs[0]), $step, $args];
    }

    /**
     * Call `$entry` on a real GislErgonomicClient by its YAML name, `submit()` it,
     * and return what it sent. Asserts the request sequence: exactly one upload
     * per stub, then ONE workflow-create. Never swallows: a local refusal, or a
     * request past the queued responses, throws and fails the case.
     *
     * @param Row $row
     * @param Entry $entry
     * @return array{
     *     payload: array<string, mixed>,
     *     stubs: list<Stub>,
     *     uploads: list<array{filename: string, type: string, content: string}>,
     *     fileIds: list<string>,
     * }
     */
    private function exercise(array $row, array $entry): array
    {
        $stubs = $this->stubsFor($row, $entry);
        $fileIds = [];
        $queue = [];
        foreach ($stubs as $i => $stub) {
            // Distinct per upload, and UUIDv7-shaped: UploadResponse hydration enforces the pattern.
            $fileIds[] = $fileId = self::FILE_ID_PREFIX . $i;
            // size_bytes at or below multipartThresholdBytes: the video probe guard reads the
            // RESPONSE's size (GislClient::maybeWaitForVideoProbe) and returns without a request.
            $queue[] = self::jsonResponse(['file_id' => $fileId, 'content_type' => 'application/octet-stream', 'size_bytes' => \strlen($stub['bytes'])]);
        }
        $queue[] = self::jsonResponse(['workflow_id' => self::WORKFLOW_ID, 'status' => 'pending']);
        $captured = [];
        $client = self::makeClient(self::stubClient($queue, $captured));

        // The by-name call below is case-insensitive too; existence is what pins the spelling.
        self::assertNull(self::existenceFailure(...self::memberOf($entry)));
        [$receiver, $method, $args] = $this->invocation($client, $row, $entry, $stubs);
        $builder = $receiver->{$method}(...$args);
        self::assertIsObject($builder);
        self::assertTrue(\method_exists($builder, 'submit'), self::entryLabel($entry) . ' returns no builder with submit()');
        $builder->submit();

        self::assertSame(
            [...\array_fill(0, \count($stubs), 'POST /api/uploads'), 'POST /api/workflows'],
            \array_map(static fn (RequestInterface $r): string => $r->getMethod() . ' ' . $r->getUri()->getPath(), $captured),
        );
        $payload = \json_decode((string) $captured[\count($stubs)]->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return [
            'payload' => $payload,
            'stubs' => $stubs,
            'uploads' => \array_map(self::uploadPart(...), \array_slice($captured, 0, \count($stubs))),
            'fileIds' => $fileIds,
        ];
    }

    /**
     * The file part of a single-shot upload body.
     *
     * @return array{filename: string, type: string, content: string}
     */
    private static function uploadPart(RequestInterface $request): array
    {
        $body = (string) $request->getBody();
        if (\preg_match('/name="file"; filename="([^"]*)"\r\nContent-Type: ([^\r\n]*)\r\n\r\n(.*)\r\n--[^\r\n]*--\r\n$/s', $body, $m) !== 1) {
            throw new \RuntimeException('upload body is not one single-shot multipart file part');
        }

        return ['filename' => $m[1], 'type' => $m[2], 'content' => $m[3]];
    }

    /**
     * Every operation the payload sends, across all jobs.
     *
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private static function operationsOf(array $payload): array
    {
        $operations = [];
        foreach ($payload['jobs'] ?? [] as $job) {
            foreach ($job['operations'] ?? [] as $operation) {
                $operations[] = $operation;
            }
        }

        return $operations;
    }

    /**
     * The upload file ids the payload's job sources reference, in job order.
     *
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function uploadSourceIds(array $payload): array
    {
        $ids = [];
        foreach ($payload['jobs'] ?? [] as $job) {
            if (($job['source']['type'] ?? null) === 'upload') {
                $ids[] = $job['source']['file_id'];
            }
        }

        return $ids;
    }

    /**
     * The upload file ids the job carrying `$operation` consumes, in the order it
     * names them (its `source`, else each of its `inputs`), followed through the
     * `job_output` edges of the passthrough source jobs down to the uploads.
     *
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function consumedUploads(array $payload, string $operation): array
    {
        $jobs = $payload['jobs'] ?? [];
        $byId = \array_column($jobs, null, 'id');
        $resolve = static function (array $job, array $seen) use (&$resolve, $byId): array {
            $ids = [];
            foreach (isset($job['source']) ? [$job['source']] : \array_column($job['inputs'] ?? [], 'source') as $source) {
                if (($source['type'] ?? null) === 'upload') {
                    $ids[] = $source['file_id'];
                } elseif (($source['type'] ?? null) === 'job_output' && isset($byId[$source['from']]) && !\in_array($source['from'], $seen, true)) {
                    $ids = [...$ids, ...$resolve($byId[$source['from']], [...$seen, $source['from']])];
                }
            }

            return $ids;
        };
        foreach ($jobs as $job) {
            if (\in_array($operation, \array_column($job['operations'] ?? [], 'type'), true)) {
                return $resolve($job, []);
            }
        }

        return [];
    }

    /**
     * Null when `$operations` is the binding the row claims, else why not: exactly
     * one operation other than the passthrough source ops, and it is `$operation`.
     * The passthrough row itself must send exactly one bare passthrough.
     *
     * @param list<array<string, mixed>> $operations
     */
    private static function bindingFailure(array $operations, string $operation): ?string
    {
        $types = \array_column($operations, 'type');
        if ($operation === self::PASSTHROUGH) {
            $bare = \count($operations) === 1 && $types[0] === self::PASSTHROUGH && ($operations[0]['options'] ?? []) === [];

            return $bare ? null : 'expected exactly one bare passthrough, got ' . \json_encode($operations);
        }
        $lowered = \array_values(\array_filter($types, static fn (mixed $type): bool => $type !== self::PASSTHROUGH));
        if (\count($lowered) !== 1) {
            return 'expected exactly one non-passthrough operation, got [' . \implode(', ', $lowered) . ']';
        }

        return $lowered[0] === $operation ? null : "lowered to {$lowered[0]}, the row says {$operation}";
    }

    /**
     * @param Row $row
     * @param Entry $entry
     */
    #[DataProvider('cases')]
    public function testEachReachableEntrySendsItsRowOperation(array $row, array $entry): void
    {
        $run = $this->exercise($row, $entry);

        self::assertNull(self::bindingFailure(self::operationsOf($run['payload']), $row['operation']));
        self::assertSame(
            $run['fileIds'],
            self::consumedUploads($run['payload'], $row['operation']),
            "the {$row['operation']} job consumes every upload, in input order",
        );
        // Upload identity: exactly the test's stubs, in order (the name carries the group's
        // extension), and the payload is built over the ids those uploads returned.
        foreach ($run['stubs'] as $i => $stub) {
            $upload = $run['uploads'][$i];
            self::assertSame([$stub['name'], $stub['bytes']], [$upload['filename'], $upload['content']], "upload {$i}");
            if ($stub['type'] !== null) {
                self::assertSame($stub['type'], $upload['type'], "upload {$i} Content-Type");
            }
        }
        self::assertSame($run['fileIds'], self::uploadSourceIds($run['payload']));
    }

    // ------------------------------------------------------------------
    // (3) Negative controls through the same checkers
    // ------------------------------------------------------------------

    public function testNegativeControlAMisspeltStepAndAGislClientMethodFailExistence(): void
    {
        // Positive control: the real step IS found, so the miss below is the spelling.
        self::assertNull(self::existenceFailure(Recipe::class, 'compress'));
        self::assertSame(Recipe::class . ' has no method compres', self::existenceFailure(Recipe::class, 'compres'));
        // A case-only misspelling: PHP's own lookup accepts it, the exact-name rule does not.
        self::assertTrue((new \ReflectionClass(Recipe::class))->hasMethod('textwatermark'));
        self::assertNull(self::existenceFailure(Recipe::class, 'textWatermark'));
        self::assertSame(
            Recipe::class . ' has no method textwatermark (only textWatermark)',
            self::existenceFailure(Recipe::class, 'textwatermark'),
        );

        // uploadFile IS public on GislClient, which GislErgonomicClient extends, so
        // the miss below is the declaring-class rule, not visibility or absence.
        self::assertNull(self::existenceFailure(GislClient::class, 'uploadFile'));
        self::assertSame(
            GislErgonomicClient::class . '::uploadFile is declared on ' . GislClient::class,
            self::existenceFailure(GislErgonomicClient::class, 'uploadFile'),
        );
    }

    public function testNegativeControlTheCompressImageRowBoundToThumbnailIsATypeMismatch(): void
    {
        $row = self::requiredRow('compress', 'image');
        $real = \array_values(\array_filter($row['entries'], static fn (array $e): bool => $e['kind'] === 'file_first'))[0] ?? null;
        self::assertNotNull($real, 'compress/image has no file-first entry');

        $right = $this->exercise($row, $real);
        self::assertNull(self::bindingFailure(self::operationsOf($right['payload']), $row['operation']));

        $wrong = $this->exercise($row, ['step' => 'thumbnail'] + $real);
        self::assertSame(
            'lowered to thumbnail, the row says compress',
            self::bindingFailure(self::operationsOf($wrong['payload']), $row['operation']),
        );
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * A temp file of a few distinct bytes, named with `$extension`.
     *
     * @return Stub
     */
    private function stub(int $index, string $extension, ?string $type): array
    {
        $temp = \tempnam(\sys_get_temp_dir(), 'gisl-op-parity-');
        self::assertIsString($temp);
        $path = "{$temp}-{$index}.{$extension}";
        \rename($temp, $path);
        $this->tempFiles[] = $path;
        $bytes = "GISL stub {$index}";
        \file_put_contents($path, $bytes);

        return ['path' => $path, 'name' => \basename($path), 'bytes' => $bytes, 'type' => $type];
    }

    /** @param Stub $stub */
    private function resourceInput(array $stub): FileInput
    {
        $stream = \fopen($stub['path'], 'rb');
        self::assertIsResource($stream);
        $this->streams[] = $stream;

        return FileInput::resource($stream, $stub['name'], contentType: $stub['type']);
    }

    /** @param array<string, mixed> $data */
    private static function jsonResponse(array $data): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], \json_encode(['success' => true, 'data' => $data], \JSON_THROW_ON_ERROR));
    }

    /**
     * Copied from SplitRecipeForwardingTest: answers from `$queue` in order and
     * throws once it is exhausted, so an unexpected extra request fails the case.
     *
     * @param list<ResponseInterface> $queue
     * @param list<RequestInterface>  $captured
     * @param-out list<RequestInterface> $captured
     */
    private static function stubClient(array $queue, array &$captured): ClientInterface
    {
        $captured = [];
        return new class ($queue, $captured) implements ClientInterface {
            /** @var list<ResponseInterface> */
            private array $queue;
            /** @var list<RequestInterface> */
            private array $captured;

            /**
             * @param list<ResponseInterface> $queue
             * @param list<RequestInterface>  $captured
             */
            public function __construct(array $queue, array &$captured)
            {
                $this->queue = $queue;
                $this->captured = &$captured;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;
                $next = \array_shift($this->queue);
                if ($next === null) {
                    throw new \RuntimeException('Stub PSR-18 client: response queue exhausted');
                }
                return $next;
            }
        };
    }

    private static function makeClient(ClientInterface $http): GislErgonomicClient
    {
        $factory = new HttpFactory();

        return new GislErgonomicClient(
            config: new GislClientConfig(
                baseUrl: 'https://api.example.com',
                streamBaseUrl: 'https://stream.example.com',
                apiKey: 'sk_test',
                multipartConcurrency: 1,
            ),
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }
}
