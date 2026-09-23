<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Sdk\Errors\GislConfigError;

/**
 * A merge asset backed by an open, seekable stream resource — the in-memory /
 * Blob analogue for merge inputs (VOxtu0RZ-B4). Construct via
 * {@see Merge::resource()}.
 *
 * Dedupe identity is the stream's resource id, so the SAME resource referenced
 * twice in a merge uploads once; two DISTINCT handles over the same bytes are
 * treated as two assets (referential identity, mirroring the TS Blob-by-
 * reference contract). A non-seekable stream is rejected by `uploadFile()` when
 * the merge runs; {@see Merge::resource()} can buffer one first (KS04SnqR).
 */
final class ResourceAsset implements Asset
{
    /**
     * @param resource $resource
     */
    public function __construct(
        public readonly mixed $resource,
    ) {
        if (!\is_resource($resource)) {
            throw new GislConfigError(
                'Merge::resource() expected an open stream resource; got ' . \get_debug_type($resource) . '.',
            );
        }
    }
}
