<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * Raised by {@see \Gisl\Sdk\GislClient::streamEvents()} before any HTTP I/O
 * when the client's configuration has **no declared SSE stream host**.
 *
 * ⚠️ **THIS ERROR IS A CONTROL, NOT A DEFECT.** The event stream lives on a
 * second host, and the SDK will not guess it. Deriving `stream.*` from `api.*`
 * by string surgery is a *convention*, and a convention is precisely what put
 * production on the gateway path: the frontend's prod build had no stream host
 * configured, fell back to the API host silently, and the failure was
 * invisible until somebody measured it. Raising here is the loud version of
 * that same situation.
 *
 * Today this is reachable in a production configuration because the contract
 * declares stream `servers` for localhost and staging only — contracts
 * deliberately did not invent a prod URL. Once the prod entry lands, a prod
 * client resolves normally and this stops firing for that case.
 *
 * Recover by passing an explicit stream base URL to {@see \Gisl\Sdk\Gisl::create()}
 * / {@see \Gisl\Sdk\GislClientConfig}, setting `GISL_STREAM_BASE_URL`, or
 * constructing with an {@see \Gisl\Sdk\Environment} that declares one.
 * `run()` does NOT surface this error — it treats an undeclared stream host as
 * "SSE unavailable for this configuration" and polls instead.
 *
 * Specialised subtype of {@see GislConfigError}, so an existing
 * `catch (GislConfigError)` still catches it. Mirrors
 * `GislStreamHostNotDeclaredError` in `packages/typescript/src/errors.ts`.
 */
final class GislStreamHostNotDeclaredError extends GislConfigError
{
}
