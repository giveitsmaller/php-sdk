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
- **Skipped (PHP)**: 4 (3 divergences in §1 + 1 withdrawn operation in §2; all with a rationale)
- **Failing**: 0

The full PHP suite (unit + parity) is green via `make project/sdk/php/check`.
Three of the four skips below are the only PHP conformance divergences; none is a
functional defect in the hand-written SDK logic, and all are tracked for
reduction. The launch gate passes with these documented exceptions.

The three in §1 fall in one bucket; §2 is not a divergence at all:

- **Bucket B — shared TS+PHP skip of an out-of-contract fixture**
  (§1 recommendedChunkSize). The TS reference runner skips the same three
  fixtures for the same reason (`packages/typescript/tests/parity/parity.test.ts`);
  the fixture pins a value below the contract chunk-range floor, so neither SDK
  accepts it.

Bucket A (the §1 multipart `file` part Content-Type PHP-specific divergence,
Trello `RWWBYklu`) is **RESOLVED** — the PHP SDK now forwards the caller-supplied
content-type from `UploadOptions` on both the single-shot and multipart-initiate
`file` parts (`packages/php/src/GislClient.php:3027`, `:3068`), defaulting to
`application/octet-stream` when absent. The `upload_small` fixture is no longer
skipped.

## Divergences

### 1. Sub-floor chunk size — both SDKs enforce the contract chunk-range (3 fixtures, Bucket B)

Both SDKs enforce the contract chunk-range floor of **16 MiB (16777216 bytes)**
on `recommendedChunkSize` (raised 5 MiB → 16 MiB by CON-1 / contracts
`z4GDTUMx`, ADR-0011): PHP via the generated
`MultipartInitiateResponse::setRecommendedChunkSize` hydrate-time validator, TS
via the same contract chunk-range guard (`packages/typescript/src/client.ts:~1097`,
a previously-lax TS path closed in codex review). These fixtures intentionally
pin a small (~2 MB) `recommended_chunk_size` to keep the binary payload
compact — a value below the contract floor — so **both** runners reject it and
skip the same three fixtures. This is a deliberate shared divergence on an
out-of-contract fixture, **not** a generator bug. The happy-path multipart wire
shape is covered elsewhere (PHP unit tests; TS `client.test.ts` /
`upload-streaming.test.ts`) with contract-valid chunk sizes.

| Fixture | Reason |
|---|---|
| `upload_multipart` | Both SDKs reject the fixture's sub-16-MiB (~2 MB) recommendedChunkSize. |
| `upload_metadata_hint` | Same sub-floor value as `upload_multipart`. |
| `upload_boundary_multipart` | Same sub-floor value as `upload_multipart`. |

**Resolution:** none planned — the fixtures pin an out-of-contract value on
purpose to keep payloads compact, and both SDKs correctly reject it. A
conformant server never sends below the 16 MiB floor.

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
