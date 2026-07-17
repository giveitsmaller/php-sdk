<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Errors;

use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Errors\GislTimeoutError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Contract test for the `workflowId` recovery field added in `oYumKo6y`.
 * Mirrors the TS assertions in
 * `packages/typescript/tests/unit/client.test.ts`.
 */
#[CoversClass(GislTimeoutError::class)]
final class GislTimeoutErrorTest extends TestCase
{
    public function test_extends_gisl_error_hierarchy(): void
    {
        $err = new GislTimeoutError('deadline elapsed');

        $this->assertInstanceOf(GislError::class, $err);
        $this->assertSame('deadline elapsed', $err->getMessage());
    }

    public function test_workflow_id_is_null_when_not_supplied_or_empty(): void
    {
        // A pre-create timeout constructs with no id; some throw sites derive
        // the id as `... ?? ''`, so an empty string must normalise to null
        // rather than leak as an unusable non-null value.
        $this->assertNull((new GislTimeoutError('Upload completed but maxWait elapsed'))->workflowId);
        $this->assertNull((new GislTimeoutError('deadline', ''))->workflowId);
    }

    public function test_workflow_id_is_exposed_when_supplied(): void
    {
        // Post-create timeouts carry the id so the caller can poll it to
        // recover the result instead of re-running (which double-charges).
        $err = new GislTimeoutError('did not complete within 0ms', 'wf-9');

        $this->assertSame('wf-9', $err->workflowId);
    }

    public function test_preserves_code_and_previous_for_chaining(): void
    {
        // The base RuntimeException `$code` / `$previous` remain constructible
        // (regression guard: the field was added without dropping chaining).
        $cause = new \RuntimeException('transport aborted');
        $err = new GislTimeoutError('did not complete', 'wf-9', 7, $cause);

        $this->assertSame(7, $err->getCode());
        $this->assertSame($cause, $err->getPrevious());
        $this->assertSame('wf-9', $err->workflowId);
    }
}
