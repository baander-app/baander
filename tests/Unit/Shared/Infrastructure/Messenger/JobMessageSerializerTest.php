<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messenger\JobMessageSerializer;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;

final class JobMessageSerializerTest extends TestCase
{
    private JobMessageSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new JobMessageSerializer(new JsonMessageCodec());
    }

    public function testSerializesValidCommandToJson(): void
    {
        $albumId = Uuid::generate();
        $command = new ExtractAlbumCoverCommand($albumId);
        $envelope = new Envelope($command);

        $result = $this->serializer->serialize($envelope);

        $this->assertNotNull($result);
        $this->assertJson($result);

        $decoded = json_decode($result, true);
        $this->assertSame('baander.message', $decoded['format']);
        $this->assertSame(1, $decoded['version']);
        $this->assertSame('metadata.extract_album_cover', $decoded['type']);
        $this->assertSame($albumId->toString(), $decoded['payload']['album_id']);
        $this->assertArrayNotHasKey('__class', $decoded);
    }

    public function testReturnsNullWhenPayloadExceedsThreshold(): void
    {
        $serializer = new JobMessageSerializer(new JsonMessageCodec(), maxPayloadSize: 1);

        $command = new ExtractAlbumCoverCommand(Uuid::generate());
        $envelope = new Envelope($command);

        $result = $serializer->serialize($envelope);

        $this->assertNull($result);
    }

    public function testReturnsNullOnSerializationFailure(): void
    {
        // Only explicitly versioned message types may be persisted for retry.
        $envelope = new Envelope(new \stdClass());

        $result = $this->serializer->serialize($envelope);

        $this->assertNull($result);
    }

    public function testDeserializeReturnsNullOnFailure(): void
    {
        $result = $this->serializer->deserialize('not-valid-json');

        $this->assertNull($result);
    }

    public function testDeserializeValidPayload(): void
    {
        $albumId = Uuid::generate();
        $data = (new JsonMessageCodec())->encode(new ExtractAlbumCoverCommand($albumId));

        $result = $this->serializer->deserialize($data);

        $this->assertNotNull($result);
        $this->assertInstanceOf(ExtractAlbumCoverCommand::class, $result);
        $this->assertTrue($albumId->equals($result->getAlbumId()));
    }
}
