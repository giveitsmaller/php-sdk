<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Errors;

use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Errors\GislFanOutTimeoutError;
use Gisl\Sdk\Errors\GislTimeoutError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Contract test for the `mapEach` fan-out timeout recovery error (`4G4FaA9X`).
 * Mirrors the TS assertions in
 * `packages/typescript/tests/unit/errors.test.ts`.
 */
#[CoversClass(GislFanOutTimeoutError::class)]
final class GislFanOutTimeoutErrorTest extends TestCase
{
    public function test_extends_gisl_timeout_error_so_existing_catch_still_catches_it(): void
    {
        // Subclasses GislTimeoutError so a `catch (GislTimeoutError)` still
        // catches the fan-out variant (consistency with oYumKo6y).
        $err = new GislFanOutTimeoutError('maxWait elapsed during fan-out (after 2 child runs).');

        $this->assertInstanceOf(GislTimeoutError::class, $err);
        $this->assertInstanceOf(GislError::class, $err);
    }

    public function test_carries_the_completed_child_ids_and_parent_id(): void
    {
        $err = new GislFanOutTimeoutError(
            'maxWait elapsed during fan-out (after 2 child runs).',
            completedWorkflowIds: ['wf_child_0', 'wf_child_1'],
            parentWorkflowId: 'wf_parent',
        );

        $this->assertSame(['wf_child_0', 'wf_child_1'], $err->completedWorkflowIds);
        $this->assertSame('wf_parent', $err->parentWorkflowId);
        // A clean between-children timeout has no in-flight child — inherited
        // workflowId stays null.
        $this->assertNull($err->workflowId);
    }

    public function test_carries_the_in_flight_child_id_on_a_mid_child_timeout(): void
    {
        // When a CHILD's own deadline elapses mid-run, the inherited workflowId
        // carries that in-flight child so the caller can poll it too.
        $cause = new GislTimeoutError('child did not complete', 'wf_child_2');
        $err = new GislFanOutTimeoutError(
            'maxWait elapsed during fan-out while a child was running (2 completed).',
            completedWorkflowIds: ['wf_child_0', 'wf_child_1'],
            parentWorkflowId: 'wf_parent',
            workflowId: 'wf_child_2',
            previous: $cause,
        );

        $this->assertSame('wf_child_2', $err->workflowId);
        $this->assertSame(['wf_child_0', 'wf_child_1'], $err->completedWorkflowIds);
        $this->assertSame('wf_parent', $err->parentWorkflowId);
        // The original child timeout is preserved for chaining.
        $this->assertSame($cause, $err->getPrevious());
    }

    public function test_empty_parent_id_normalises_to_null_and_ids_are_reindexed(): void
    {
        $err = new GislFanOutTimeoutError(
            'msg',
            completedWorkflowIds: \array_filter(['a', 'b'], static fn (string $id): bool => $id !== ''),
            parentWorkflowId: '',
        );

        $this->assertNull($err->parentWorkflowId);
        // array_values reindexes so the public shape is always a 0-based list.
        $this->assertSame(['a', 'b'], $err->completedWorkflowIds);
    }

    public function test_preserves_code_and_previous_for_chaining(): void
    {
        $cause = new \RuntimeException('transport aborted');
        $err = new GislFanOutTimeoutError('msg', code: 7, previous: $cause);

        $this->assertSame(7, $err->getCode());
        $this->assertSame($cause, $err->getPrevious());
    }
}
