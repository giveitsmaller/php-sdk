<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * Options for a fluent `files([...])->archive(...)` bundle. Both fields are
 * optional — the server defaults `format` to `zip` and `folder_structure` to
 * `flat` (archive op schema). Mirrors the TS `ArchiveRecipeOptions` interface at
 * `packages/typescript/src/file-first.ts` 1:1 (name + shape), matching the
 * options-object call-shape used by every other multi-input builder
 * (`merge(MergeOptions)`).
 *
 * @see \Gisl\Sdk\FileFirst\FilesRecipe::archive()
 * @see \Gisl\Sdk\FileFirst\ArchivedRecipe
 */
final class ArchiveRecipeOptions
{
    /**
     * @param ArchiveFormat|string|null $format          Archive container format
     *                                                    (`zip` / `tar.gz`); an
     *                                                    {@see ArchiveFormat} case
     *                                                    or its string value.
     * @param "flat"|"by_job"|null      $folderStructure `flat` = all files at the
     *                                                    top level; `by_job` = a
     *                                                    subfolder per source.
     */
    public function __construct(
        public readonly ArchiveFormat|string|null $format = null,
        public readonly ?string $folderStructure = null,
    ) {
    }
}
