# PHP parity-runner KNOWN_DIVERGENCES

Each entry below is a shared cross-SDK fixture under `tests/parity/fixtures/`
that the PHP parity runner deliberately **skips**. Every skip is a known PHP
divergence from the cross-language parity spec — an upstream openapi-generator
emitter quirk or a documented SDK behaviour — **not** a bug in the runner and
**not** masked by comparator hacks. Each is linked to a follow-up Trello card so
the list shrinks over time.

This file is the human-readable companion to the machine source of truth, the
`KNOWN_DIVERGENCES` const in
[`ParityTest.php`](./ParityTest.php) (`markTestSkipped` is driven from that
array). Keep the two in sync — see "How to remove a skip" below.

## Status snapshot (v1 launch gate — P8 `ftI4Myby`)

- **Shared fixtures**: 74
- **Skipped (PHP)**: 1 (the withdrawn operation in §2, which is not a divergence)
- **Failing**: 0

The full PHP suite (unit + parity) is green via `make project/sdk/php/check`.
There is no PHP conformance divergence left to skip: the one remaining skip is a
fixture both SDKs agree to refuse. The launch gate passes with it documented.

Bucket B (§1, the shared TS+PHP skip of three fixtures that pinned an
out-of-contract `recommendedChunkSize`) is **RESOLVED** — the fixtures now pin
the contract floor and run in both SDKs.

Bucket A (the §1 multipart `file` part Content-Type PHP-specific divergence,
Trello `RWWBYklu`) is **RESOLVED** — the PHP SDK now forwards the caller-supplied
content-type from `UploadOptions` on both the single-shot and multipart-initiate
`file` parts (`packages/php/src/GislClient.php:3027`, `:3068`), defaulting to
`application/octet-stream` when absent. The `upload_small` fixture is no longer
skipped.

## Divergences

### 1. Sub-floor chunk size — RESOLVED (3 fixtures, Bucket B)

Both SDKs enforce the contract chunk-range floor of **16 MiB (16777216 bytes)**
on `recommendedChunkSize` (raised 5 MiB → 16 MiB by CON-1 / contracts
`z4GDTUMx`, ADR-0011): PHP via the generated
`MultipartInitiateResponse::setRecommendedChunkSize` hydrate-time validator, TS
via the same contract chunk-range guard in `packages/typescript/src/client.ts`.
`upload_multipart`, `upload_metadata_hint` and `upload_boundary_multipart`
pinned a ~2 MB `recommended_chunk_size`, below that floor, so both runners
rejected and skipped them.

**Resolution (`xbWn0diW`):** the three fixtures now pin `16777216`. The value
never kept the payload compact: each upload is an 8 MiB first chunk plus a tail
shorter than one chunk, so the part plan (2 parts, one presigned PUT) and every
request byte are unchanged. The same change quoted their `expires_at` timestamps,
which the PHP harness's YAML loader read as an integer while every running
fixture already quoted theirs. They are the only cross-SDK proof of initiate,
then PUT, then complete, and run in both SDKs again.

### 2. Withdrawn operation — NOT a divergence (1 fixture)

⚠️ **This section is here because the harness has one skip mechanism and it is
named for the other reason.** The two SDKs do not disagree about this fixture:
they **agree to refuse it**.

`contracts v2.203.0` withdrew `video_watermark` from `stable` to
`availability: planned` on the owner's GO, after three measured staging proofs —
the worker exists and cannot serve the advertised ceiling. Both SDKs now throw at
`.watermark()` before any payload exists, so a lowering fixture has nothing to
compare.

| Fixture | Reason |
|---|---|
| `ff_lowering_video_watermark_overlay` | `video_watermark` is `planned`; both SDKs refuse a video base before lowering. The fixture still describes the CORRECT shape for when the op is re-listed. |

🔑 **Restore it by removing the entry from both harnesses** — `ParityTest.php`
and `parity.test.ts` — when `video_watermark` is shippable again. Until then the
video lowering shape is uncovered in both languages, which is a real gap and is
recorded here rather than left to be rediscovered.

## How to remove a skip

1. The underlying issue is fixed (in the SDK source, the generator-compat
   layer, the contracts package, or the fixture).
2. Remove the fixture's entry from the `KNOWN_DIVERGENCES` const in
   [`ParityTest.php`](./ParityTest.php).
3. Remove the corresponding row from this file (and the status snapshot count).
4. Run `make project/sdk/php/check` and confirm the parity suite is green.
