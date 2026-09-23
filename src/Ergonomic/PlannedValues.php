<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Generated\Operations\OperationMetadata;
use Gisl\Generated\Operations\OptionMetadata;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\OperationDef;

/**
 * Generic per-VALUE `planned` gate for the operation-first builders (99Da2uyx).
 * Mirrors `packages/typescript/src/ergonomic/planned_values.ts`.
 *
 * The image-output gate (`ImageOutputRoutes::isPlannedValue`) covers compress
 * image routes only. Every other SINGLE-input operation-first call sent a value
 * the contract marks `planned` straight to the upload, and the API refused it at create, AFTER the
 * bytes had gone up. First live case: contracts v2.209.0 marks `split`
 * `precision: exact` planned on audio and video.
 *
 * ⚠️ THE RULE IS "PLANNED EVERYWHERE IT CAN APPLY", NOT "PLANNED ANYWHERE". This
 * runs before the upload, when the SDK does not reliably know which mime group
 * the server will resolve the input to. A value is refused only when EVERY group
 * that declares the option (plus `direct_options`) marks it planned. A false
 * refusal of a request the server would accept is worse than a late one.
 *
 * SCOPE: OperationBuilder, the file-first single-input Recipe preflight, and the
 * shared multi-input preflight in MultiInputUpload (merge, files()->archive(),
 * overlays, fan-out, batch) - every lowered operation of every job (pE6JVJuc).
 *
 * @internal
 */
final class PlannedValues
{
    public static function isPlannedEverywhere(string $opType, string $optionKey, mixed $value): bool
    {
        $meta = self::metadataFor($opType);
        if ($meta === null) {
            return false;
        }
        /** @var list<OptionMetadata> $declaring */
        $declaring = [];
        foreach ($meta->mime_groups as $group) {
            if (isset($group->options[$optionKey])) {
                $declaring[] = $group->options[$optionKey];
            }
        }
        if (isset($meta->direct_options[$optionKey])) {
            $declaring[] = $meta->direct_options[$optionKey];
        }
        if ($declaring === []) {
            return false;
        }
        $token = \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        foreach ($declaring as $option) {
            $entry = $option->per_value_availability[$token] ?? null;
            if ($entry === null || $entry->availability !== 'planned') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $wireOptions
     * @return array{key: string, value: mixed}|null
     */
    public static function firstPlannedValue(string $opType, array $wireOptions): ?array
    {
        foreach ($wireOptions as $key => $value) {
            // A generated backed enum (e.g. SplitAudioPrecision::Exact) is the
            // typed spelling of its backing value; compare that (codex
            // ecd2cea89752), not skip it as an object.
            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            }
            if ($value === null || \is_array($value) || \is_object($value)) {
                continue;
            }
            if (self::isPlannedEverywhere($opType, (string) $key, $value)) {
                return ['key' => (string) $key, 'value' => $value];
            }
        }

        return null;
    }

    /**
     * Throw the pre-upload refusal for the first planned-everywhere value in any
     * of `$operations`. Shared by the file-first preflights (pE6JVJuc).
     *
     * @param iterable<OperationDef> $operations
     */
    public static function refuseInOperations(iterable $operations): void
    {
        foreach ($operations as $op) {
            $planned = self::firstPlannedValue($op->type, $op->options ?? []);
            if ($planned !== null) {
                $shown = \is_bool($planned['value'])
                    ? ($planned['value'] ? 'true' : 'false')
                    : (\is_scalar($planned['value']) ? (string) $planned['value'] : \get_debug_type($planned['value']));
                throw new GislConfigError(
                    "{$op->type}: '{$planned['key']}: {$shown}' is advertised but not available yet (planned).",
                    reason: 'feature_not_available',
                    conflictingFields: [$planned['key']],
                );
            }
        }
    }

    private static function metadataFor(string $opType): ?OperationMetadata
    {
        $class = 'Gisl\\Generated\\Operations\\'
            . \str_replace(' ', '', \ucwords(\str_replace('_', ' ', $opType))) . 'Metadata';
        if (!\class_exists($class) || !\method_exists($class, 'instance')) {
            return null;
        }
        $meta = $class::instance();

        return $meta instanceof OperationMetadata ? $meta : null;
    }
}
