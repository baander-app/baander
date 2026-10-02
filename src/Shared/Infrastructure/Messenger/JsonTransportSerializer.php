<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use App\Shared\Infrastructure\Messenger\Stamp\CorrelationIdStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/** Maps framework envelopes to the explicit JSON contract; never serializes PHP objects. */
final readonly class JsonTransportSerializer implements SerializerInterface
{
    public function __construct(private JsonMessageCodec $codec)
    {
    }

    public function encode(Envelope $envelope): array
    {
        $metadata = [];
        foreach ($envelope->all() as $history) {
            // Workers only consume the latest stamp of each kind. Bound retry history.
            $stamp = $history[array_key_last($history)];
            if ($stamp instanceof NonSendableStampInterface) {
                continue;
            }
            if ($stamp instanceof HandledStamp) {
                $names = array_map(static fn (HandledStamp $item): string => $item->getHandlerName(), $envelope->all(HandledStamp::class));
                if (count($names) > 64) {
                    throw new \InvalidArgumentException('Too many completed message handlers.');
                }
                $metadata[] = ['type' => 'handled', 'data' => ['names' => $names]];
                continue;
            }
            [$type, $data] = match (true) {
                $stamp instanceof TransportMessageIdStamp => ['transport_id', ['id' => $stamp->getId()]],
                $stamp instanceof BusNameStamp => ['bus', ['name' => $stamp->getBusName()]],
                $stamp instanceof CorrelationIdStamp => ['correlation', ['id' => $stamp->correlationId]],
                $stamp instanceof JobIdStamp => ['job', ['id' => $stamp->jobId->toString()]],
                $stamp instanceof DelayStamp => ['delay', ['milliseconds' => $stamp->getDelay()]],
                $stamp instanceof RedeliveryStamp => ['retry', ['count' => $stamp->getRetryCount(), 'at' => $stamp->getRedeliveredAt()->format(\DateTimeInterface::RFC3339_EXTENDED)]],
                $stamp instanceof SentToFailureTransportStamp => ['failure', ['receiver' => $stamp->getOriginalReceiverName()]],
                $stamp instanceof TransportNamesStamp => ['transports', ['names' => $stamp->getTransportNames()]],
                // Retain diagnostics, without serializing exception objects or stack arguments.
                $stamp instanceof ErrorDetailsStamp => ['error', ['class' => $stamp->getExceptionClass(), 'code' => $stamp->getExceptionCode(), 'message' => $stamp->getExceptionMessage()]],
                default => throw new \InvalidArgumentException('Unsupported sendable message stamp.'),
            };
            $this->decodeStamp($type, $data);
            $metadata[] = ['type' => $type, 'data' => $data];
        }
        return ['body' => $this->codec->encode($envelope->getMessage(), ['stamps' => $metadata]), 'headers' => ['Content-Type' => 'application/json']];
    }

    /** @param array<string, mixed> $encodedEnvelope Untrusted transport input, validated before decoding. */
    public function decode(array $encodedEnvelope): Envelope
    {
        try {
            if (!isset($encodedEnvelope['body']) || !is_string($encodedEnvelope['body'])) {
                throw new \InvalidArgumentException('Missing message body.');
            }
            $decoded = $this->codec->decode($encodedEnvelope['body']);
            $metadata = $decoded->metadata;
            if (array_keys($metadata) !== ['stamps'] || !is_array($metadata['stamps']) || !array_is_list($metadata['stamps']) || count($metadata['stamps']) > 10) {
                throw new \InvalidArgumentException('Invalid transport metadata.');
            }
            $stamps = [];
            $seen = [];
            foreach ($metadata['stamps'] as $item) {
                if (!is_array($item) || count($item) !== 2 || !isset($item['type'], $item['data']) || !is_string($item['type']) || !is_array($item['data']) || isset($seen[$item['type']])) {
                    throw new \InvalidArgumentException('Invalid or duplicate message stamp.');
                }
                $seen[$item['type']] = true;
                $stamp = $this->decodeStamp($item['type'], $item['data']);
                array_push($stamps, ...(is_array($stamp) ? $stamp : [$stamp]));
            }
            return new Envelope($decoded->message, $stamps);
        } catch (\Throwable $error) {
            throw new MessageDecodingFailedException('Invalid Baander message envelope.', previous: $error);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return StampInterface|list<HandledStamp>
     */
    private function decodeStamp(string $type, array $data): StampInterface|array
    {
        $fields = match ($type) {
            'bus' => ['name'], 'correlation', 'job', 'transport_id' => ['id'], 'delay' => ['milliseconds'],
            'retry' => ['count', 'at'], 'failure' => ['receiver'], 'transports', 'handled' => ['names'],
            'error' => ['class', 'code', 'message'],
            default => throw new \InvalidArgumentException('Unknown message stamp.'),
        };
        if (array_diff($fields, array_keys($data)) !== [] || array_diff(array_keys($data), $fields) !== []) {
            throw new \InvalidArgumentException('Invalid stamp fields.');
        }
        return match ($type) {
            'transport_id' => new TransportMessageIdStamp(is_int($data['id']) ? $data['id'] : $this->text($data['id'])),
            'handled' => array_map(static fn (string $name): HandledStamp => new HandledStamp(null, $name), $this->names($data['names'], 64)),
            'bus' => new BusNameStamp($this->text($data['name'])),
            'correlation' => new CorrelationIdStamp($this->text($data['id'])),
            'job' => new JobIdStamp(PublicId::fromString($this->text($data['id']))),
            'delay' => new DelayStamp($this->nonnegative($data['milliseconds'])),
            'retry' => new RedeliveryStamp($this->nonnegative($data['count']), $this->date($data['at'])),
            'failure' => new SentToFailureTransportStamp($this->text($data['receiver'])),
            'transports' => new TransportNamesStamp($this->names($data['names'])),
            'error' => new ErrorDetailsStamp($this->text($data['class']), is_int($data['code']) ? $data['code'] : $this->text($data['code']), $this->text($data['message'])),
        };
    }

    private function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Expected stamp text.');
        }
        return $value;
    }

    private function nonnegative(mixed $value): int
    {
        if (!is_int($value) || $value < 0) {
            throw new \InvalidArgumentException('Expected nonnegative stamp integer.');
        }
        return $value;
    }

    private function date(mixed $value): \DateTimeImmutable
    {
        $text = $this->text($value);
        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339_EXTENDED, $text);
        if ($date === false || $date->format(\DateTimeInterface::RFC3339_EXTENDED) !== $text) {
            throw new \InvalidArgumentException('Invalid retry timestamp.');
        }
        return $date;
    }

    /** @return list<string> */
    private function names(mixed $value, int $limit = 16): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $limit) {
            throw new \InvalidArgumentException('Invalid transport names.');
        }
        return array_map($this->text(...), $value);
    }
}
