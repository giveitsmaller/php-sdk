<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Parity;

/**
 * Compares fixture-declared expectations against captured runtime values.
 *
 * Mirrors `packages/typescript/tests/parity/comparators.ts`. Token grammar
 * is deliberately identical:
 *   <any>, <string>, <uuid>, <iso8601>, <int>, <timestamp_unix>, <etag>,
 *   <hex:N>, <base64:N>
 *
 * Tokens are honoured in REQUEST positions only — `expected_return`
 * comparison is strict deep-equal per the TS reference (fixture.schema.json
 * §38). One PHP-specific concession: ISO-8601 string ↔ ISO-8601 string
 * cross-format compare (PHP's `\DateTime::ATOM` emits `+00:00`, fixtures
 * author `Z`); we treat those as equal because they encode the same instant.
 */
final class Comparator
{
    private const TOKEN_PATTERN = '/^<(any|string|uuid|iso8601|int|timestamp_unix|etag|hex:\d+|base64:\d+)>$/';

    /**
     * @return list<string> Issues; empty list = pass.
     */
    public static function compareRequests(
        Fixture $fixture,
        StubPsr18Client $stub,
    ): array {
        $expected = $fixture->requests;
        $captured = $stub->captured();
        if (\count($expected) !== \count($captured)) {
            return [
                "requests: expected " . \count($expected) . " request(s), captured " . \count($captured),
            ];
        }
        $issues = [];
        foreach ($expected as $i => $exp) {
            $issues = \array_merge(
                $issues,
                self::compareRequest($exp, $captured[$i], "requests[{$i}]", $fixture->absolutePath),
            );
        }
        return $issues;
    }

    /**
     * @param array<string, mixed> $expected
     * @return list<string>
     */
    private static function compareRequest(
        array $expected,
        CapturedRequest $captured,
        string $path,
        string $fixtureFile,
    ): array {
        $issues = [];
        $expMethod = (string) ($expected['method'] ?? '');
        if ($expMethod !== $captured->method) {
            $issues[] = "{$path}.method: expected {$expMethod}, got {$captured->method}";
        }

        $expPath = (string) ($expected['path'] ?? '');
        $isAbsolute = \preg_match('#^https?://#i', $expPath) === 1;
        $expPathOnly = self::stripQuery($expPath);
        $capPathOnly = $isAbsolute ? self::stripQuery($captured->url) : $captured->path;
        if (!self::matchPath($expPathOnly, $capPathOnly)) {
            $issues[] = "{$path}.path: expected \"{$expPathOnly}\", got \"{$capPathOnly}\"";
        }

        $expQuery = self::mergeQuery(self::parseQuery($expPath), self::asStringMap($expected['query'] ?? []));
        foreach (self::compareQuery($expQuery, $captured->query, "{$path}.query") as $issue) {
            $issues[] = $issue;
        }

        /** @var array<string, string> $expHeaders */
        $expHeaders = self::asStringMap($expected['headers'] ?? []);
        foreach (self::compareHeaders($expHeaders, $captured->headers, "{$path}.headers") as $issue) {
            $issues[] = $issue;
        }

        /** @var array<string, mixed> $expBody */
        $expBody = isset($expected['body']) && \is_array($expected['body'])
            ? $expected['body']
            : ['type' => 'empty'];
        foreach (self::compareBody($expBody, $captured, "{$path}.body", $fixtureFile) as $issue) {
            $issues[] = $issue;
        }

        return $issues;
    }

    private static function stripQuery(string $p): string
    {
        $i = \strpos($p, '?');
        return $i === false ? $p : \substr($p, 0, $i);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function parseQuery(string $p): array
    {
        $i = \strpos($p, '?');
        if ($i === false) {
            return [];
        }
        $out = [];
        foreach (\explode('&', \substr($p, $i + 1)) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = \strpos($pair, '=');
            if ($eq === false) {
                $k = \rawurldecode($pair);
                $v = '';
            } else {
                $k = \rawurldecode(\substr($pair, 0, $eq));
                $v = \rawurldecode(\substr($pair, $eq + 1));
            }
            $out[$k] ??= [];
            $out[$k][] = $v;
        }
        return $out;
    }

    /**
     * @param array<string, list<string>> $a
     * @param array<string, string>       $b
     * @return array<string, list<string>>
     */
    private static function mergeQuery(array $a, array $b): array
    {
        $out = $a;
        foreach ($b as $k => $v) {
            $out[$k] ??= [];
            $out[$k][] = $v;
        }
        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function asStringMap(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (\is_string($k)) {
                $out[$k] = (string) $v;
            }
        }
        return $out;
    }

    private static function matchPath(string $expected, string $actual): bool
    {
        $expSegs = \explode('/', $expected);
        $actSegs = \explode('/', $actual);
        if (\count($expSegs) !== \count($actSegs)) {
            return false;
        }
        foreach ($expSegs as $i => $seg) {
            if (!self::matchString($seg, $actSegs[$i])) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, list<string>> $expected
     * @param array<string, list<string>> $captured
     * @return list<string>
     */
    private static function compareQuery(array $expected, array $captured, string $path): array
    {
        $issues = [];
        $expKeys = \array_keys($expected);
        $capKeys = \array_keys($captured);
        \sort($expKeys);
        \sort($capKeys);
        if (\implode(',', $expKeys) !== \implode(',', $capKeys)) {
            $issues[] = "{$path}: expected keys [" . \implode(',', $expKeys) . '], got [' . \implode(',', $capKeys) . ']';
            return $issues;
        }
        foreach ($expKeys as $k) {
            $expVals = $expected[$k];
            $capVals = $captured[$k];
            \sort($expVals);
            \sort($capVals);
            if (\count($expVals) !== \count($capVals)) {
                $issues[] = "{$path}.{$k}: expected " . \count($expVals) . " value(s), got " . \count($capVals);
                continue;
            }
            foreach ($expVals as $i => $expVal) {
                if (!self::matchString($expVal, $capVals[$i])) {
                    $issues[] = "{$path}.{$k}[{$i}]: expected \"{$expVal}\", got \"{$capVals[$i]}\"";
                }
            }
        }
        return $issues;
    }

    /**
     * @param array<string, string> $expected
     * @param array<string, string> $captured
     * @return list<string>
     */
    private static function compareHeaders(array $expected, array $captured, string $path): array
    {
        $issues = [];
        foreach ($expected as $rawKey => $expVal) {
            $key = \strtolower($rawKey);
            $capVal = $captured[$key] ?? null;
            if ($capVal === null) {
                $issues[] = "{$path}.{$key}: header missing; expected \"{$expVal}\"";
                continue;
            }
            if (!self::matchString($expVal, $capVal)) {
                $issues[] = "{$path}.{$key}: expected \"{$expVal}\", got \"{$capVal}\"";
            }
        }
        return $issues;
    }

    /**
     * @param array<string, mixed> $expected
     * @return list<string>
     */
    private static function compareBody(
        array $expected,
        CapturedRequest $captured,
        string $path,
        string $fixtureFile,
    ): array {
        $expType = (string) ($expected['type'] ?? 'empty');
        $capType = (string) ($captured->body['type'] ?? 'empty');
        if ($expType !== $capType) {
            return ["{$path}: expected body type \"{$expType}\", got \"{$capType}\""];
        }
        switch ($expType) {
            case 'empty':
                return [];
            case 'json':
                $expValue = $expected['value'] ?? null;
                $capValue = $captured->body['value'] ?? null;
                return self::compareValueRequest($expValue, $capValue, "{$path}.value");
            case 'multipart':
                /** @var list<array<string, mixed>> $expParts */
                $expParts = $expected['parts'] ?? [];
                return self::compareMultipart($expParts, $captured->multipartParts, $path, $fixtureFile);
            case 'raw':
                $expContent = $expected['content'] ?? null;
                if (!\is_array($expContent)) {
                    return ["{$path}: raw body missing content"];
                }
                /** @var array<string, mixed> $expContent */
                $expBytes = BytesDecoder::decode($expContent, $fixtureFile);
                $capBytes = (string) ($captured->body['bytes'] ?? '');
                return self::compareBytes($expBytes, $capBytes, "{$path}.content");
        }
        return ["{$path}: unsupported body type \"{$expType}\""];
    }

    /**
     * @param list<array<string, mixed>> $expected
     * @param list<array<string, mixed>> $captured
     * @return list<string>
     */
    private static function compareMultipart(
        array $expected,
        array $captured,
        string $path,
        string $fixtureFile,
    ): array {
        // Match parts as a multiset keyed by name. Repeated names are paired
        // by position-within-name so a fixture="a a b" vs captured="a b" diff
        // is loud rather than silently passing.
        $byName = [];
        foreach ($captured as $part) {
            $name = (string) ($part['name'] ?? '');
            $byName[$name] ??= [];
            $byName[$name][] = $part;
        }
        $expCounts = [];
        foreach ($expected as $p) {
            $n = (string) ($p['name'] ?? '');
            $expCounts[$n] = ($expCounts[$n] ?? 0) + 1;
        }
        $expSig = self::sigFromCounts($expCounts);
        $capCounts = [];
        foreach ($byName as $n => $list) {
            $capCounts[$n] = \count($list);
        }
        $capSig = self::sigFromCounts($capCounts);
        if ($expSig !== $capSig) {
            return ["{$path}: expected multipart parts [{$expSig}], got [{$capSig}]"];
        }

        $issues = [];
        $seen = [];
        foreach ($expected as $exp) {
            $name = (string) ($exp['name'] ?? '');
            $idx = $seen[$name] ?? 0;
            $seen[$name] = $idx + 1;
            $cap = $byName[$name][$idx] ?? null;
            if ($cap === null) {
                $issues[] = "{$path}.{$name}[{$idx}]: captured part missing";
                continue;
            }
            $issues = \array_merge(
                $issues,
                self::comparePart($exp, $cap, "{$path}.{$name}[{$idx}]", $fixtureFile),
            );
        }
        return $issues;
    }

    /**
     * @param array<string, int> $counts
     */
    private static function sigFromCounts(array $counts): string
    {
        \ksort($counts);
        $pairs = [];
        foreach ($counts as $n => $c) {
            $pairs[] = "{$n}x{$c}";
        }
        return \implode('|', $pairs);
    }

    /**
     * @param array<string, mixed> $exp
     * @param array<string, mixed> $cap
     * @return list<string>
     */
    private static function comparePart(array $exp, array $cap, string $path, string $fixtureFile): array
    {
        $issues = [];
        $expFilename = $exp['filename'] ?? null;
        if (\is_string($expFilename)) {
            $capFilename = (string) ($cap['filename'] ?? '');
            if (!self::matchString($expFilename, $capFilename)) {
                $issues[] = "{$path}.filename: expected \"{$expFilename}\", got \"{$capFilename}\"";
            }
        }
        $expCt = $exp['content-type'] ?? null;
        if (\is_string($expCt)) {
            $capCt = (string) ($cap['contentType'] ?? '');
            if (!self::matchString($expCt, $capCt)) {
                $issues[] = "{$path}.content-type: expected \"{$expCt}\", got \"{$capCt}\"";
            }
        }

        $content = $exp['content'] ?? [];
        if (!\is_array($content)) {
            return $issues;
        }
        /** @var array<string, mixed> $content */
        $kind = (string) ($content['kind'] ?? '');
        switch ($kind) {
            case 'bytes':
                $expBytes = BytesDecoder::decode($content, $fixtureFile);
                $capBytes = isset($cap['bytes']) ? (string) $cap['bytes'] : (
                    isset($cap['text']) ? (string) $cap['text'] : ''
                );
                $issues = \array_merge(
                    $issues,
                    self::compareBytes($expBytes, $capBytes, "{$path}.content"),
                );
                break;
            case 'text':
                $expText = (string) ($content['value'] ?? '');
                $capText = isset($cap['text']) ? (string) $cap['text'] : (
                    isset($cap['bytes']) ? (string) $cap['bytes'] : ''
                );
                if (!self::matchString($expText, $capText)) {
                    $issues[] = "{$path}.content: expected \"{$expText}\", got \"{$capText}\"";
                }
                break;
            case 'json':
                $rawText = isset($cap['text']) ? (string) $cap['text'] : (
                    isset($cap['bytes']) ? (string) $cap['bytes'] : ''
                );
                if ($rawText === '') {
                    $issues[] = "{$path}.content: expected json but captured part has no body";
                    break;
                }
                try {
                    /** @var mixed $parsed */
                    $parsed = \json_decode($rawText, associative: true, flags: JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    $issues[] = "{$path}.content: failed to parse json: " . $e->getMessage();
                    break;
                }
                $issues = \array_merge(
                    $issues,
                    self::compareValueRequest($content['value'] ?? null, $parsed, "{$path}.content"),
                );
                break;
            default:
                $issues[] = "{$path}.content: unsupported content.kind \"{$kind}\"";
        }
        return $issues;
    }

    /**
     * @return list<string>
     */
    private static function compareBytes(string $expected, string $actual, string $path): array
    {
        if (\strlen($expected) !== \strlen($actual)) {
            return ["{$path}: expected " . \strlen($expected) . " bytes, got " . \strlen($actual)];
        }
        if ($expected !== $actual) {
            // Find the first differing byte for an actionable diff message.
            $len = \strlen($expected);
            for ($i = 0; $i < $len; $i++) {
                if ($expected[$i] !== $actual[$i]) {
                    $expByte = \ord($expected[$i]);
                    $actByte = \ord($actual[$i]);
                    return [
                        "{$path}: byte {$i} differs (expected 0x"
                        . \dechex($expByte)
                        . ', got 0x'
                        . \dechex($actByte)
                        . ')',
                    ];
                }
            }
        }
        return [];
    }

    // -----------------------------------------------------------------------
    // Value comparison — request side honours tokens; expected_return side is
    // strict deep-equal per the TS comparator.
    // -----------------------------------------------------------------------

    /**
     * Token-aware value comparison used inside JSON request bodies.
     *
     * @return list<string>
     */
    private static function compareValueRequest(mixed $expected, mixed $actual, string $path): array
    {
        return self::compareValueImpl($expected, $actual, $path, honourTokens: true);
    }

    /**
     * Strict deep-equal value comparison used for `expected_return`.
     *
     * @return list<string>
     */
    public static function compareReturn(mixed $expected, mixed $actual, string $path): array
    {
        return self::compareValueImpl($expected, $actual, $path, honourTokens: false);
    }

    /**
     * @return list<string>
     */
    private static function compareValueImpl(
        mixed $expected,
        mixed $actual,
        string $path,
        bool $honourTokens,
    ): array {
        // Date coercion: \DateTimeInterface -> ISO 8601 string. `ObjectSerializer`
        // formats DateTime via \DateTime::ATOM (`...+00:00`); fixtures author
        // ISO strings as `...Z`. Normalise both sides to a UTC instant for the
        // textual compare so equivalent ISO encodings don't fail parity.
        if ($expected instanceof \DateTimeInterface) {
            $expected = $expected->format(\DateTimeInterface::ATOM);
        }
        if ($actual instanceof \DateTimeInterface) {
            $actual = $actual->format(\DateTimeInterface::ATOM);
        }

        if ($expected === null && $actual === null) {
            return [];
        }
        if ($expected === null || $actual === null) {
            return ["{$path}: expected " . self::dumpScalar($expected) . ', got ' . self::dumpScalar($actual)];
        }

        if (\is_string($expected)) {
            if (!\is_string($actual)) {
                return ["{$path}: expected string, got " . \get_debug_type($actual)];
            }
            // For return-side compare, attempt date-aware normalisation so
            // ATOM `+00:00` matches fixture `Z` form.
            if (!$honourTokens) {
                $expNorm = self::normaliseIsoString($expected);
                $actNorm = self::normaliseIsoString($actual);
                if ($expNorm !== null && $actNorm !== null) {
                    return $expNorm === $actNorm
                        ? []
                        : ["{$path}: expected \"{$expected}\", got \"{$actual}\""];
                }
                return $expected === $actual
                    ? []
                    : ["{$path}: expected \"{$expected}\", got \"{$actual}\""];
            }
            // honourTokens=true branch: token-aware string match.
            return self::matchString($expected, $actual)
                ? []
                : ["{$path}: expected \"{$expected}\", got \"{$actual}\""];
        }

        if (\is_bool($expected)) {
            return $expected === $actual
                ? []
                : ["{$path}: expected " . self::dumpScalar($expected) . ', got ' . self::dumpScalar($actual)];
        }
        if (\is_int($expected) || \is_float($expected)) {
            // YAML loaders disagree on whether `1.0` is an int or a float,
            // and PHP's json_encode emits `1.0` as `1` so the wire round-trip
            // can re-parse a fixture's `opacity: 1.0` as int. Treat numerically
            // equal int/float as equal — boolean is excluded from this branch
            // so `true == 1` does not slip through.
            if ((\is_int($actual) || \is_float($actual)) && (float) $expected === (float) $actual) {
                return [];
            }
            return ["{$path}: expected " . self::dumpScalar($expected) . ', got ' . self::dumpScalar($actual)];
        }

        if (\is_array($expected) && \array_is_list($expected)) {
            if (!\is_array($actual) || !\array_is_list($actual)) {
                return ["{$path}: expected list, got " . \get_debug_type($actual)];
            }
            if (\count($expected) !== \count($actual)) {
                return ["{$path}: expected length " . \count($expected) . ', got ' . \count($actual)];
            }
            $issues = [];
            foreach ($expected as $i => $expItem) {
                $issues = \array_merge(
                    $issues,
                    self::compareValueImpl($expItem, $actual[$i] ?? null, "{$path}[{$i}]", $honourTokens),
                );
            }
            return $issues;
        }

        if (\is_array($expected)) {
            // Associative array — coerce the actual to one too.
            if ($actual instanceof \stdClass) {
                $actual = (array) $actual;
            }
            if (!\is_array($actual) || \array_is_list($actual)) {
                return ["{$path}: expected object, got " . \get_debug_type($actual)];
            }
            $expKeys = self::definedKeys($expected);
            $actKeys = self::definedKeys($actual);
            \sort($expKeys);
            \sort($actKeys);
            if (\implode('|', $expKeys) !== \implode('|', $actKeys)) {
                return [
                    "{$path}: key sets differ; expected ["
                    . \implode(',', $expKeys)
                    . '], got ['
                    . \implode(',', $actKeys)
                    . ']',
                ];
            }
            $issues = [];
            foreach ($expKeys as $k) {
                $issues = \array_merge(
                    $issues,
                    self::compareValueImpl(
                        $expected[$k] ?? null,
                        $actual[$k] ?? null,
                        "{$path}.{$k}",
                        $honourTokens,
                    ),
                );
            }
            return $issues;
        }

        return ["{$path}: unsupported value type " . \get_debug_type($expected)];
    }

    /**
     * @param array<string|int, mixed> $arr
     * @return list<string>
     */
    private static function definedKeys(array $arr): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            if ($v === null) {
                // Mirror the TS comparator: undefined keys are filtered on
                // both sides because openapi-generator's FromJSON helpers
                // populate missing-from-wire fields as null. We only filter
                // when the expected side ALSO has null at that key — an
                // unexpected null IS a divergence and surfaces as a key-set
                // mismatch later.
                continue;
            }
            $out[] = (string) $k;
        }
        return $out;
    }

    private static function dumpScalar(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (\is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (\is_int($v) || \is_float($v)) {
            return (string) $v;
        }
        if (\is_string($v)) {
            return '"' . $v . '"';
        }
        return \get_debug_type($v);
    }

    /**
     * Parse an ISO 8601 string and return its canonical UTC representation.
     * Returns `null` if the input doesn't parse — caller falls back to
     * literal compare.
     */
    private static function normaliseIsoString(string $value): ?string
    {
        // Cheap pre-filter — only attempt date parsing on plausibly-shaped
        // strings. PHP's DateTime parser is permissive and would happily
        // turn "1" or "test" into a valid datetime.
        if (\preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value) !== 1) {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    }

    public static function isToken(string $value): bool
    {
        return \preg_match(self::TOKEN_PATTERN, $value) === 1;
    }

    /**
     * Token-aware string matcher. Mirrors `matchString` in
     * `packages/typescript/tests/parity/comparators.ts`.
     */
    public static function matchString(string $expected, string $actual): bool
    {
        if (!self::isToken($expected)) {
            return $expected === $actual;
        }
        if (\preg_match('/^<hex:(\d+)>$/', $expected, $m) === 1) {
            return \preg_match('/^[0-9a-fA-F]{' . $m[1] . '}$/', $actual) === 1;
        }
        if (\preg_match('/^<base64:(\d+)>$/', $expected, $m) === 1) {
            return \preg_match('/^[A-Za-z0-9+\/]{' . $m[1] . '}={0,2}$/', $actual) === 1;
        }
        switch ($expected) {
            case '<any>':
            case '<string>':
                return $actual !== '';
            case '<uuid>':
                return \preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $actual) === 1;
            case '<iso8601>':
                return \preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', $actual) === 1;
            case '<int>':
                return \preg_match('/^-?\d+$/', $actual) === 1;
            case '<timestamp_unix>':
                return \preg_match('/^\d{10,13}$/', $actual) === 1;
            case '<etag>':
                // RFC 7232 weak-or-strong opaque ETag. The TS reference pins
                // the quote-wrapping invariant; honour the same.
                return \preg_match('/^"[^"\x00-\x1f]*"$/', $actual) === 1;
        }
        return false;
    }

    // -----------------------------------------------------------------
    // F4-A (C45ogrGx) — v2 assertion-block comparators.
    // -----------------------------------------------------------------

    private const PRESET_CONFIG_HASH_RE = '/^sha256:[0-9a-f]{64}$/';

    /**
     * @param array<string, mixed> $expected Fixture-side resolvedOptions block.
     * @param array<string, mixed>|null $actual  Adapter-side captured projection.
     * @return list<string>
     */
    public static function compareResolvedOptions(array $expected, ?array $actual, string $path = 'resolvedOptions'): array
    {
        if ($actual === null) {
            return ["{$path}: expected resolvedOptions but result carried none"];
        }
        $issues = [];
        $expectedPreset = $expected['preset'] ?? null;
        $actualPreset = $actual['preset'] ?? null;
        if ($expectedPreset !== $actualPreset) {
            $issues[] = "{$path}.preset: expected " . \json_encode($expectedPreset) . ', got ' . \json_encode($actualPreset);
        }
        $expectedVersion = $expected['presetVersion'] ?? null;
        $actualVersion = $actual['presetVersion'] ?? null;
        if ($expectedVersion !== $actualVersion) {
            $issues[] = "{$path}.presetVersion: expected " . \json_encode($expectedVersion) . ', got ' . \json_encode($actualVersion);
        }
        if (isset($expected['applied']) || isset($actual['applied'])) {
            $expApplied = $expected['applied'] ?? [];
            $actApplied = $actual['applied'] ?? [];
            $issues = \array_merge(
                $issues,
                self::compareReturn($expApplied, $actApplied, "{$path}.applied"),
            );
        }
        $expSources = $expected['sources'] ?? [];
        $actSources = $actual['sources'] ?? [];
        if (!\is_array($expSources) || !\is_array($actSources)) {
            $issues[] = "{$path}.sources must be a mapping on both sides";
        } else {
            foreach (['sdkDefault', 'clientDefault', 'scopedDefault', 'callPresetOverride', 'explicit'] as $bucket) {
                $expBucket = $expSources[$bucket] ?? [];
                $actBucket = $actSources[$bucket] ?? [];
                if ($expBucket !== $actBucket) {
                    $issues[] = "{$path}.sources.{$bucket}: expected " . \json_encode($expBucket) . ', got ' . \json_encode($actBucket);
                }
            }
        }
        // presetConfigHash — fixtures pin presence (any sha256: string)
        // OR explicit null/absent. Content-derived → regex-match only.
        $expectedHash = $expected['presetConfigHash'] ?? null;
        $actualHash = $actual['presetConfigHash'] ?? null;
        if (\array_key_exists('presetConfigHash', $expected)) {
            if ($expectedHash === null) {
                if ($actualHash !== null) {
                    $issues[] = "{$path}.presetConfigHash: expected absent/null, got " . \json_encode($actualHash);
                }
            } elseif (!\is_string($actualHash) || \preg_match(self::PRESET_CONFIG_HASH_RE, $actualHash) !== 1) {
                $issues[] = "{$path}.presetConfigHash: expected /^sha256:[0-9a-f]{64}$/, got " . \json_encode($actualHash);
            }
        }
        return $issues;
    }

    /**
     * Assert that none of the listed wire fields appears in any
     * captured request body. Returns empty list on pass.
     *
     * @param list<string>       $omitted  Fields that MUST NOT appear.
     * @param list<CapturedRequest> $captured Captured outbound requests.
     * @return list<string>
     */
    public static function compareOmittedFromWire(array $omitted, array $captured, string $path = 'omittedFromWire'): array
    {
        $issues = [];
        foreach ($omitted as $field) {
            foreach ($captured as $idx => $req) {
                if (self::bodyContainsField($req, $field)) {
                    $issues[] = "{$path}.{$field}: field appeared in captured requests[{$idx}] body but fixture asserts it should be omitted from the wire";
                }
            }
        }
        return $issues;
    }

    private static function bodyContainsField(CapturedRequest $req, string $field): bool
    {
        $body = $req->body;
        $type = $body['type'] ?? null;
        if ($type === 'json' && isset($body['value']) && \is_array($body['value'])) {
            return self::arrayContainsField($body['value'], $field);
        }
        if ($type === 'multipart') {
            foreach ($req->multipartParts as $part) {
                // Captured multipart parts carry their decoded payload as
                // `text` or `bytes` directly (see StubPsr18Client::parseMultipart),
                // NOT a `content` sub-array. If the part looks like JSON (by
                // contentType or first-char sniff), parse and recurse; non-JSON
                // parts can't carry structured fields so they trivially satisfy
                // the omitted-from-wire assertion. Mirrors the TS bodyContainsField.
                $text = isset($part['text'])
                    ? (string) $part['text']
                    : (isset($part['bytes']) ? (string) $part['bytes'] : null);
                if ($text === null) {
                    continue;
                }
                $ct = \strtolower((string) ($part['contentType'] ?? ''));
                $trimmed = \ltrim($text);
                $looksJson = \str_contains($ct, 'json')
                    || \str_starts_with($trimmed, '{')
                    || \str_starts_with($trimmed, '[');
                if (!$looksJson) {
                    continue;
                }
                try {
                    /** @var mixed $parsed */
                    $parsed = \json_decode($text, associative: true, flags: JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                if (\is_array($parsed) && self::arrayContainsField($parsed, $field)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array<string|int, mixed> $value
     */
    private static function arrayContainsField(array $value, string $field): bool
    {
        if (\array_key_exists($field, $value)) {
            return true;
        }
        foreach ($value as $nested) {
            if (\is_array($nested) && self::arrayContainsField($nested, $field)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Compare the SDK's caught local-validation error (projected by the
     * runner into the standard `{category, code, conflictingFields,
     * message}` shape) against the fixture's expectation.
     *
     * @param array<string, mixed> $expected Fixture localValidationError block.
     * @param array<string, mixed>|null $actual   Projected captured error or null.
     * @return list<string>
     */
    public static function compareLocalValidationError(array $expected, ?array $actual, string $path = 'localValidationError'): array
    {
        if ($actual === null) {
            return ["{$path}: expected a local validation error to be thrown, but no error was caught"];
        }
        $issues = [];
        if (($expected['category'] ?? null) !== ($actual['category'] ?? null)) {
            $issues[] = "{$path}.category: expected " . \json_encode($expected['category'] ?? null) . ', got ' . \json_encode($actual['category'] ?? null);
        }
        if (($expected['code'] ?? null) !== ($actual['code'] ?? null)) {
            $issues[] = "{$path}.code: expected " . \json_encode($expected['code'] ?? null) . ', got ' . \json_encode($actual['code'] ?? null);
        }
        if (\array_key_exists('conflictingFields', $expected)) {
            $exp = $expected['conflictingFields'] ?? [];
            $act = $actual['conflictingFields'] ?? [];
            if ($exp !== $act) {
                $issues[] = "{$path}.conflictingFields: expected " . \json_encode($exp) . ', got ' . \json_encode($act);
            }
        }
        if (\array_key_exists('message', $expected) && ($expected['message'] ?? null) !== ($actual['message'] ?? null)) {
            $issues[] = "{$path}.message: expected " . \json_encode($expected['message'] ?? null) . ', got ' . \json_encode($actual['message'] ?? null);
        }
        return $issues;
    }

    /**
     * Exozpn36 — project a caught throwable into the language-neutral shape
     * {@see compareThrownError} consumes. Mirrors the TS `projectThrownError`.
     * Class by SHORT name when it lives in `Gisl\Sdk\Errors` (the two SDKs
     * share class names); any other throwable reports its FQCN so it can never
     * match. Payload fields are requested by WIRE name and read from the TYPED
     * payload through the generated model's getter for that name, because that
     * is what a consumer reads; a field the payload does not expose is absent.
     *
     * @param list<string> $wireFields
     * @return array{className: string, hasKind: bool, kind: mixed, payloadFields: array<string, mixed>}
     */
    public static function projectThrownError(?\Throwable $thrown, array $wireFields): array
    {
        if ($thrown === null) {
            return ['className' => 'null', 'hasKind' => false, 'kind' => null, 'payloadFields' => []];
        }
        $reflection = new \ReflectionClass($thrown);
        $className = $reflection->getNamespaceName() === 'Gisl\Sdk\Errors'
            ? $reflection->getShortName()
            : $reflection->getName();

        $payloadFields = [];
        $typed = \property_exists($thrown, 'typedPayload') ? $thrown->typedPayload : null;
        if (\is_object($typed) && \method_exists($typed, 'getters')) {
            /** @var array<string, string> $getters */
            $getters = $typed::getters();
            foreach ($wireFields as $wire) {
                if (isset($getters[$wire])) {
                    $payloadFields[$wire] = $typed->{$getters[$wire]}();
                }
            }
        }

        $hasKind = \property_exists($thrown, 'kind');
        return [
            'className' => $className,
            'hasKind' => $hasKind,
            'kind' => $hasKind ? $thrown->kind : null,
            'payloadFields' => $payloadFields,
        ];
    }

    /**
     * Exozpn36 — compare a projected throwable with the fixture's
     * expected_error_class / expected_error_kind / expected_payload_fields.
     * Each is optional and independent. Issue text mirrors the TS
     * `compareThrownError` so a divergence reads the same in both runners.
     *
     * @param array{className: string, hasKind: bool, kind: mixed, payloadFields: array<string, mixed>} $actual
     * @return list<string>
     */
    public static function compareThrownError(Fixture $fixture, array $actual): array
    {
        $issues = [];
        if ($fixture->expectedErrorClass !== null && $fixture->expectedErrorClass !== $actual['className']) {
            $issues[] = "expected_error_class: expected {$fixture->expectedErrorClass}, got {$actual['className']}";
        }
        if ($fixture->expectedErrorKind !== null && $fixture->expectedErrorKind !== $actual['kind']) {
            $issues[] = $actual['hasKind']
                ? 'expected_error_kind: expected ' . \json_encode($fixture->expectedErrorKind) . ', got ' . \json_encode($actual['kind'])
                : 'expected_error_kind: expected ' . \json_encode($fixture->expectedErrorKind) . ", but {$actual['className']} carries no kind";
        }
        foreach ($fixture->expectedPayloadFields ?? [] as $field => $value) {
            if (!\array_key_exists($field, $actual['payloadFields'])) {
                $issues[] = "expected_payload_fields.{$field}: expected " . \json_encode($value)
                    . ", but {$actual['className']}'s typed payload does not expose it";
            } elseif ($actual['payloadFields'][$field] !== $value) {
                $issues[] = "expected_payload_fields.{$field}: expected " . \json_encode($value)
                    . ', got ' . \json_encode($actual['payloadFields'][$field]);
            }
        }
        return $issues;
    }
}
