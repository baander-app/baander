<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messenger\JobMessageSerializer;
use App\Shared\Infrastructure\Messenger\JobMessageSerializerFactory;
use App\Shared\Infrastructure\Messenger\PublicIdNormalizer;
use App\Shared\Infrastructure\Messenger\UuidNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class JobMessageSerializerTest extends TestCase
{
    private JobMessageSerializer $serializer;

    protected function setUp(): void
    {
        $propertyAccessor = new PropertyAccessor();
        $serializer = JobMessageSerializerFactory::create(
            $propertyAccessor,
            new UuidNormalizer(),
            new PublicIdNormalizer(),
        );

        $this->serializer = new JobMessageSerializer(
            serializer: $serializer,
            maxPayloadSize: 1_048_576,
            jsonEncoder: new JsonEncoder(),
        );
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
        $this->assertArrayHasKey('albumId', $decoded);
        $this->assertSame($albumId->toString(), $decoded['albumId']);
        $this->assertArrayHasKey('__class', $decoded);
        $this->assertSame(ExtractAlbumCoverCommand::class, $decoded['__class']);
    }

    public function testReturnsNullWhenPayloadExceedsThreshold(): void
    {
        $propertyAccessor = new PropertyAccessor();
        $serializer = JobMessageSerializerFactory::create(
            $propertyAccessor,
            new UuidNormalizer(),
            new PublicIdNormalizer(),
        );

        $serializer = new JobMessageSerializer(
            serializer: $serializer,
            maxPayloadSize: 1,
            jsonEncoder: new JsonEncoder(),
        );

        $command = new ExtractAlbumCoverCommand(Uuid::generate());
        $envelope = new Envelope($command);

        $result = $serializer->serialize($envelope);

        $this->assertNull($result);
    }

    public function testReturnsNullOnSerializationFailure(): void
    {
        // stdClass is not in the allowed patterns, so AllowedClassNormalizer returns null
        // which causes a serialization error upstream
        $envelope = new Envelope(new \stdClass());

        $result = $this->serializer->serialize($envelope);

        $this->assertNull($result);
    }

    public function testDeserializeReturnsNullOnFailure(): void
    {
        $result = $this->serializer->deserialize('not-valid-json', ExtractAlbumCoverCommand::class);

        $this->assertNull($result);
    }

    public function testDeserializeValidPayload(): void
    {
        $albumId = Uuid::generate();
        $data = json_encode([
            '__class' => ExtractAlbumCoverCommand::class,
            'albumId' => $albumId->toString(),
        ]);

        $result = $this->serializer->deserialize($data);

        $this->assertNotNull($result);
        $this->assertInstanceOf(ExtractAlbumCoverCommand::class, $result);
    }
}
