<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\JobDefinitionPayload;
use Gisl\Sdk\OperationDef;
use Gisl\Sdk\Sources;
use Gisl\Sdk\WorkflowCreatePayload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkflowCreatePayload::class)]
final class WorkflowCreatePayloadTest extends TestCase
{
    public function testToWireMinimalEmitsJobsOnly(): void
    {
        $payload = new WorkflowCreatePayload(
            jobs: [
                new JobDefinitionPayload(
                    operations: [new OperationDef(type: 'compress')],
                    source: Sources::upload('file_a'),
                ),
            ],
        );

        $wire = $payload->toWire();
        self::assertSame(['jobs'], \array_keys($wire));
        self::assertCount(1, $wire['jobs']);
    }

    public function testToWireEmitsAllOptionalsWhenSet(): void
    {
        $payload = new WorkflowCreatePayload(
            jobs: [
                new JobDefinitionPayload(
                    operations: [new OperationDef(type: 'compress')],
                    source: Sources::upload('file_a'),
                ),
            ],
            workflowEdges: [['from' => 'a', 'to' => 'b']],
            callbackUrl: 'https://hook.example.com/gisl',
            callbackEvents: ['workflow.completed', 'workflow.failed'],
            exportPayload: ['type' => 'connection', 'connection_id' => 'c1', 'path' => 'out/'],
            delivery: ['mode' => 'bundle', 'bundle_format' => 'zip'],
            processing: ['class_hint' => 'auto'],
            notify: ['email' => ['to' => ['user@example.com']]],
        );

        $wire = $payload->toWire();
        self::assertSame(
            ['jobs', 'workflow_edges', 'callback_url', 'callback_events', 'export', 'delivery', 'processing', 'notify'],
            \array_keys($wire),
        );
        self::assertSame([['from' => 'a', 'to' => 'b']], $wire['workflow_edges']);
        self::assertSame('https://hook.example.com/gisl', $wire['callback_url']);
        self::assertSame(['workflow.completed', 'workflow.failed'], $wire['callback_events']);
        self::assertSame(
            ['type' => 'connection', 'connection_id' => 'c1', 'path' => 'out/'],
            $wire['export'],
        );
        self::assertSame(['mode' => 'bundle', 'bundle_format' => 'zip'], $wire['delivery']);
        self::assertSame(['class_hint' => 'auto'], $wire['processing']);
        self::assertSame(['email' => ['to' => ['user@example.com']]], $wire['notify']);
    }

    public function testToWireOmitsNullOptionalsIndividually(): void
    {
        // Only callbackUrl set; other optionals stay null and must NOT
        // appear in the wire payload.
        $payload = new WorkflowCreatePayload(
            jobs: [
                new JobDefinitionPayload(
                    operations: [new OperationDef(type: 'compress')],
                    source: Sources::upload('file_a'),
                ),
            ],
            callbackUrl: 'https://hook.example.com/gisl',
        );

        $wire = $payload->toWire();
        self::assertArrayHasKey('callback_url', $wire);
        self::assertArrayNotHasKey('workflow_edges', $wire);
        self::assertArrayNotHasKey('callback_events', $wire);
        self::assertArrayNotHasKey('export', $wire);
        self::assertArrayNotHasKey('delivery', $wire);
        self::assertArrayNotHasKey('processing', $wire);
        self::assertArrayNotHasKey('notify', $wire);
    }
}
