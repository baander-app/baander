<?php

declare(strict_types=1);

namespace App\Tests\Unit\UserPreference\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\Port\AudioPreferencesPortInterface;
use App\UserPreference\Interface\Controller\AudioPreferencesController;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AudioPreferencesControllerTest extends TestCase
{
    private AudioPreferencesPortInterface $port;
    private ValidatorInterface $validator;
    private JsonEncoder $jsonEncoder;
    private Security $security;
    private AudioPreferencesController $controller;

    protected function setUp(): void
    {
        $this->port = $this->createStub(AudioPreferencesPortInterface::class);
        $this->validator = $this->createStub(ValidatorInterface::class);
        $this->jsonEncoder = $this->createStub(JsonEncoder::class);
        $this->security = $this->createStub(Security::class);

        $this->controller = $this->createAudioPreferencesControllerFixture();
    }

    private function createAudioPreferencesControllerFixture(): AudioPreferencesController
    {
        $fixture = new AudioPreferencesController(
            $this->port,
            $this->validator,
            $this->jsonEncoder,
            $this->security,
        );

        $user = new SecurityUser(
            id: '01911111-1111-7111-8111-111111111111',
            email: 'user@example.com',
            password: 'hashed-pw',
        );
        $this->security->method('getUser')->willReturn($user);
        return $fixture;
    }

    // --- GET returns stored preferences with version (200) ---

    public function testGetReturnsStoredPreferences(): void
    {
        $request = $this->createJsonRequest('GET', '');

        $payload = ['enabled' => true, 'preset' => 'flat'];
        $this->port->method('getForUser')->willReturn($payload);
        $this->port->method('getVersion')->willReturn(3);

        $response = $this->controller->index($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(3, $data['data']['version']);
        $this->assertTrue($data['data']['payload']['enabled']);
    }

    // --- GET for user with no preferences returns 404 ---

    public function testGetReturns404WhenNoPreferences(): void
    {
        $request = $this->createJsonRequest('GET', '');

        $this->port->method('getForUser')->willReturn(null);

        $response = $this->controller->index($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    // --- PUT saves and returns new version (200) ---

    public function testPutSavesAndReturnsNewVersion(): void
    {
        $this->port = $this->createMock(AudioPreferencesPortInterface::class);
        $this->controller = $this->createAudioPreferencesControllerFixture();

        $inputData = ['payload' => ['enabled' => true, 'preset' => 'flat'], 'version' => 1];

        $request = $this->createJsonRequest('PUT', json_encode($inputData));

        $this->jsonEncoder->method('decode')->willReturn($inputData);
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $this->port->expects($this->once())
            ->method('saveForUser')
            ->with($this->isInstanceOf(Uuid::class), $this->callback(fn (array $p) => true), 1)
            ->willReturn(2);

        $response = $this->controller->update($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(2, $data['data']['version']);
    }

    // --- PUT with stale version returns 409 ---

    public function testPutReturns409OnVersionMismatch(): void
    {
        $inputData = ['payload' => ['enabled' => true, 'preset' => 'flat'], 'version' => 1];

        $request = $this->createJsonRequest('PUT', json_encode($inputData));

        $this->jsonEncoder->method('decode')->willReturn($inputData);
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $this->port->method('saveForUser')->willThrowException(new \RuntimeException('Version mismatch'));
        $this->port->method('getVersion')->willReturn(5);

        $response = $this->controller->update($request);

        $this->assertSame(409, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(5, $data['error']['details']['currentVersion']);
    }

    // --- GET /history returns version list ---

    public function testHistoryReturnsVersionList(): void
    {
        $request = $this->createJsonRequest('GET', '');

        $history = [
            ['version' => 3, 'payload' => [], 'created_at' => '2026-05-10T10:00:00+00:00'],
            ['version' => 2, 'payload' => [], 'created_at' => '2026-05-09T10:00:00+00:00'],
            ['version' => 1, 'payload' => [], 'created_at' => '2026-05-08T10:00:00+00:00'],
        ];

        $this->port->method('getHistory')->willReturn($history);

        $response = $this->controller->history($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertCount(3, $data['data']['history']);
        $this->assertSame(3, $data['data']['history'][0]['version']);
    }

    // --- POST /rollback restores specified version ---

    public function testRollbackRestoresSpecifiedVersion(): void
    {
        $this->port = $this->createMock(AudioPreferencesPortInterface::class);
        $this->controller = $this->createAudioPreferencesControllerFixture();

        $request = $this->createJsonRequest('POST', json_encode(['version' => 2]));

        $this->jsonEncoder->method('decode')->willReturn(['version' => 2]);
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $this->port->expects($this->once())
            ->method('rollbackTo')
            ->with($this->isInstanceOf(Uuid::class), 2)
            ->willReturn(['enabled' => false, 'preset' => 'rock']);

        $this->port->method('getVersion')->willReturn(4);

        $response = $this->controller->rollback($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(4, $data['data']['version']);
    }

    // --- PUT with invalid payload returns 422 ---

    public function testPutReturns422OnInvalidPayload(): void
    {
        $this->port = $this->createMock(AudioPreferencesPortInterface::class);
        $this->controller = $this->createAudioPreferencesControllerFixture();

        $request = $this->createJsonRequest('PUT', json_encode(['payload' => [], 'version' => 0]));

        $this->jsonEncoder->method('decode')->willReturn(['payload' => [], 'version' => 0]);

        $violations = new ConstraintViolationList([
            new ConstraintViolation(
                'Payload is required.',
                null,
                [],
                '',
                'payload',
                null,
            ),
        ]);

        $this->validator->method('validate')->willReturn($violations);

        $response = $this->controller->update($request);

        $this->assertSame(422, $response->getStatusCode());

        $this->port->expects($this->never())->method('saveForUser');
    }

    // --- Helper ---

    private function createJsonRequest(string $method, string $content): Request
    {
        return Request::create('/api/user/audio-preferences', $method, [], [], [], ['CONTENT_TYPE' => 'application/json'], $content);
    }
}
