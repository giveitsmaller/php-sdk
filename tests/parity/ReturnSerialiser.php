<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Parity;

use Gisl\Generated\OpenApi\ObjectSerializer;
use Gisl\Sdk\Ergonomic\Handle;
use Gisl\Sdk\Ergonomic\Result;
use Gisl\Sdk\GetSchemaHitResult;
use Gisl\Sdk\GetSchemaNotModifiedResult;
use Gisl\Sdk\GislSseEvent;
use Gisl\Sdk\PreflightClipError;
use Gisl\Sdk\PreflightClipsResult;

/**
 * Convert an SDK return value into a fixture-comparable canonical form.
 *
 * The fixtures pin TS-side `FromJSON`-deserialised shapes — camelCase keys,
 * ISO-8601 strings for dates, no `null` for absent optionals. The PHP SDK
 * returns generated openapi-generator model objects whose `attributeMap`
 * uses snake_case. Bridge here:
 *
 *   1. `ObjectSerializer::sanitizeForSerialization()` turns the model tree
 *      into stdClass with snake_case keys + `\DateTime` instances.
 *   2. {@see normalise} walks the tree, snake_case → camelCase, DateTime →
 *      ISO 8601 string, drops keys whose value is `null` (mirrors the TS
 *      comparator's `undefined`-filter on both sides).
 *
 * Sealed-shape PHP wrappers (`GetSchemaHitResult`, `GetSchemaNotModifiedResult`,
 * `PreflightClipsResult`, `GislSseEvent`) need bespoke handling because they
 * are SDK-side composites without the openapi-generator metadata.
 */
final class ReturnSerialiser
{
    /**
     * @return mixed Canonical comparable form (assoc array, list, scalar, null).
     */
    public static function serialise(mixed $value): mixed
    {
        // Sealed-shape wrappers first — these are not openapi-generator models.
        if ($value instanceof GetSchemaHitResult) {
            return [
                'notModified' => false,
                'etag' => $value->etag,
                'lastModified' => $value->lastModified,
                'data' => self::serialise($value->schema),
            ];
        }
        if ($value instanceof GetSchemaNotModifiedResult) {
            return [
                'notModified' => true,
                'etag' => $value->etag,
                'lastModified' => $value->lastModified,
            ];
        }
        if ($value instanceof PreflightClipsResult) {
            return [
                'ok' => \array_map(self::serialise(...), $value->ok),
                'rejected' => \array_map(self::serialise(...), $value->rejected),
                'errors' => \array_map(
                    static fn (PreflightClipError $e): array => [
                        'fileId' => $e->fileId,
                        'error' => $e->error->getMessage(),
                    ],
                    $value->errors,
                ),
            ];
        }
        if ($value instanceof GislSseEvent) {
            // `name` only on the unknown arm, as in TS (absent, not null, on a named event).
            return $value->name === null
                ? ['event' => $value->event, 'data' => $value->data]
                : ['event' => $value->event, 'name' => $value->name, 'data' => $value->data];
        }
        // Ergonomic-layer wrappers (PHP P2 / 7QXkzoIi). Their `toArray()`
        // projections already emit camelCase keys with absent-optional keys
        // dropped — the comparator can compare directly.
        if ($value instanceof Handle) {
            return $value->toArray();
        }
        if ($value instanceof Result) {
            return $value->toArray();
        }

        if ($value === null || \is_scalar($value)) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }

        // Generated openapi model OR plain array/list — route through the
        // openapi-generator's serialiser to flatten model trees, then
        // recursively normalise keys + DateTimes.
        $sanitised = ObjectSerializer::sanitizeForSerialization($value);
        return self::normalise($sanitised);
    }

    /**
     * @param mixed $value
     */
    private static function normalise(mixed $value): mixed
    {
        if ($value === null || \is_scalar($value)) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }
        if (\is_array($value)) {
            // Lists stay lists.
            if (\array_is_list($value)) {
                return \array_map(self::normalise(...), $value);
            }
            // Associative array — recurse + camelCase keys.
            $out = [];
            foreach ($value as $k => $v) {
                $key = \is_string($k) ? self::snakeToCamel($k) : (string) $k;
                $out[$key] = self::normalise($v);
            }
            return $out;
        }
        if ($value instanceof \stdClass) {
            $out = [];
            /** @var array<string, mixed> $assoc */
            $assoc = (array) $value;
            foreach ($assoc as $k => $v) {
                $out[self::snakeToCamel((string) $k)] = self::normalise($v);
            }
            return $out;
        }
        // Fallback — turn arbitrary objects into their string form so the
        // comparator surfaces the type rather than dying inside a recursion.
        return (string) \get_debug_type($value);
    }

    private static function snakeToCamel(string $key): string
    {
        if (!\str_contains($key, '_')) {
            return $key;
        }
        $parts = \explode('_', $key);
        $first = \array_shift($parts);
        $out = $first;
        foreach ($parts as $p) {
            $out .= \ucfirst($p);
        }
        return $out;
    }
}
