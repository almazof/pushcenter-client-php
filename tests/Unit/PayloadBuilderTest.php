<?php

declare(strict_types=1);

namespace PushCenter\Client\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PushCenter\Client\Exception\ValidationException;
use PushCenter\Client\Payload\Payload;
use PushCenter\Client\Payload\PayloadBuilder;

final class PayloadBuilderTest extends TestCase
{
    public function testBuildsFullPayloadShape(): void
    {
        $payload = (new PayloadBuilder())
            ->event('booking_created', 'evt-1', ['bookingId' => 91])
            ->deeplink('TripDetailsScreen', ['tripId' => '42'])
            ->ui('Уфа → Казань', 'Новое бронирование', '')
            ->build();

        self::assertSame([
            'event' => ['type' => 'booking_created', 'id' => 'evt-1', 'data' => ['bookingId' => 91]],
            'deeplink' => ['target' => 'TripDetailsScreen', 'params' => ['tripId' => '42']],
            'ui' => ['title' => 'Уфа → Казань', 'subtitle' => '', 'body' => 'Новое бронирование'],
        ], $payload->toArray());
    }

    public function testMissingEventIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        (new PayloadBuilder())->ui('T', 'B')->build();
    }

    public function testMissingUiIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        (new PayloadBuilder())->event('x_event', 'e1')->build();
    }

    public function testBadEventTypeIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        (new PayloadBuilder())->event('BadType', 'e1')->ui('T', 'B')->build();
    }

    public function testNonStringDeeplinkParamIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        /** @phpstan-ignore argument.type (deliberately wrong runtime type) */
        (new PayloadBuilder())->event('x_event', 'e1')->deeplink('S', ['id' => 42])->ui('T', 'B')->build();
    }

    public function testByteLimitBoundary(): void
    {
        $skeleton = static function (string $pad): array {
            return [
                'event' => ['type' => 'x_event', 'id' => 'e1', 'data' => ['pad' => $pad]],
                'ui' => ['title' => 'T', 'body' => 'B'],
            ];
        };
        $overhead = Payload::measureEncodedBytes($skeleton(''));

        $exact = $skeleton(str_repeat('x', Payload::MAX_ENCODED_BYTES - $overhead));
        self::assertSame(Payload::MAX_ENCODED_BYTES, Payload::fromArray($exact)->encodedBytes());

        $this->expectException(ValidationException::class);
        Payload::fromArray($skeleton(str_repeat('x', Payload::MAX_ENCODED_BYTES - $overhead + 1)));
    }

    public function testByteMeasurementUsesUnescapedUnicode(): void
    {
        // "я" is 2 UTF-8 bytes, not 6 bytes of \uXXXX escaping.
        $bytes = Payload::measureEncodedBytes(['event' => ['type' => 'x_event', 'id' => 'я']]);
        self::assertSame(strlen('{"event":{"type":"x_event","id":"я"}}'), $bytes);
    }
}
