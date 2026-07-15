<?php

declare(strict_types=1);

namespace Gisl\Sdk;

/**
 * Input to {@see GislClient::createWorkflow()}.
 *
 * Mirrors `packages/typescript/src/types.ts:67-89`. Most fields are optional;
 * the server applies defaults / validates.
 */
final class WorkflowCreatePayload
{
    /**
     * @param list<JobDefinitionPayload>           $jobs              At least one. The server rejects empty.
     * @param list<array{from: string, to: string}>|null $workflowEdges Explicit job dependencies. Most workflows
     *                                                                  infer edges from `source.from` and can omit.
     * @param list<string>|null                    $callbackEvents    Subscribable callback event types.
     * @param array<string, mixed>|null            $exportPayload     `ExternalDestinationPayload` wire shape.
     * @param array<string, mixed>|null            $delivery          `DeliveryPayload` wire shape.
     * @param array<string, mixed>|null            $processing        `WorkflowProcessingPayload` wire shape.
     * @param array<string, mixed>|null            $notify            `NotifyConfig` wire shape (contracts v2.164.0
     *                                                                 `notify.email`). Opaque passthrough — the
     *                                                                 ergonomic `notifyEmail` surface is a separate
     *                                                                 follow-up (card y6jsQCpb).
     */
    public function __construct(
        public readonly array $jobs,
        public readonly ?array $workflowEdges = null,
        public readonly ?string $callbackUrl = null,
        public readonly ?array $callbackEvents = null,
        public readonly ?array $exportPayload = null,
        public readonly ?array $delivery = null,
        public readonly ?array $processing = null,
        public readonly ?array $notify = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $payload = [
            'jobs' => array_map(
                static fn (JobDefinitionPayload $job): array => $job->toWire(),
                $this->jobs,
            ),
        ];
        if ($this->workflowEdges !== null) {
            $payload['workflow_edges'] = $this->workflowEdges;
        }
        if ($this->callbackUrl !== null) {
            $payload['callback_url'] = $this->callbackUrl;
        }
        if ($this->callbackEvents !== null) {
            $payload['callback_events'] = $this->callbackEvents;
        }
        if ($this->exportPayload !== null) {
            $payload['export'] = $this->exportPayload;
        }
        if ($this->delivery !== null) {
            $payload['delivery'] = $this->delivery;
        }
        if ($this->processing !== null) {
            $payload['processing'] = $this->processing;
        }
        if ($this->notify !== null) {
            $payload['notify'] = $this->notify;
        }
        return $payload;
    }
}
