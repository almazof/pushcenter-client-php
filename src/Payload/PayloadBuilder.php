<?php

declare(strict_types=1);

namespace PushCenter\Client\Payload;

use PushCenter\Client\Exception\ValidationException;

/**
 * Fluent builder of the notification payload (SPEC-PAYLOAD §1).
 * All validation (required event.type / event.id / ui.title / ui.body,
 * field patterns, the 3500-byte limit) happens in build() via
 * Payload::fromArray() — a Payload instance is valid by construction.
 */
final class PayloadBuilder
{
    private ?string $eventType = null;
    private ?string $eventId = null;
    /** @var array<string, mixed> */
    private array $eventData = [];
    private ?string $deeplinkTarget = null;
    /** @var array<string, string> */
    private array $deeplinkParams = [];
    private ?string $title = null;
    private ?string $body = null;
    private ?string $subtitle = null;

    /** @param array<string, mixed> $data */
    public function event(string $type, string $id, array $data = []): self
    {
        $this->eventType = $type;
        $this->eventId = $id;
        $this->eventData = $data;

        return $this;
    }

    /** @param array<string, string> $params Contract: string values only */
    public function deeplink(string $target, array $params = []): self
    {
        $this->deeplinkTarget = $target;
        $this->deeplinkParams = $params;

        return $this;
    }

    public function ui(string $title, string $body, ?string $subtitle = null): self
    {
        $this->title = $title;
        $this->body = $body;
        $this->subtitle = $subtitle;

        return $this;
    }

    /** @throws ValidationException when required fields are missing or limits exceeded */
    public function build(): Payload
    {
        if ($this->eventType === null || $this->eventId === null) {
            throw ValidationException::clientSide('event(type, id) is required before build()');
        }
        if ($this->title === null || $this->body === null) {
            throw ValidationException::clientSide('ui(title, body) is required before build()');
        }

        $event = ['type' => $this->eventType, 'id' => $this->eventId];
        if ($this->eventData !== []) {
            $event['data'] = $this->eventData;
        }

        $payload = ['event' => $event];
        if ($this->deeplinkTarget !== null) {
            $deeplink = ['target' => $this->deeplinkTarget];
            if ($this->deeplinkParams !== []) {
                $deeplink['params'] = $this->deeplinkParams;
            }
            $payload['deeplink'] = $deeplink;
        }

        $ui = ['title' => $this->title];
        if ($this->subtitle !== null) {
            $ui['subtitle'] = $this->subtitle;
        }
        $ui['body'] = $this->body;
        $payload['ui'] = $ui;

        return Payload::fromArray($payload);
    }
}
