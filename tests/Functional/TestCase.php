<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

abstract class TestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;
    protected UserRepositoryInterface $userRepository;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->userRepository = $container->get(UserRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        static::ensureKernelShutdown();
        parent::tearDown();
    }

    protected function createTestUser(?string $email = null, string $name = 'Test User', string $password = 'password123'): User
    {
        $email ??= 'test-' . bin2hex(random_bytes(4)) . '@example.com';

        $user = User::register(
            new Email($email),
            password_hash($password, PASSWORD_BCRYPT),
            $name,
        );

        $this->userRepository->save($user);

        return $user;
    }

    protected function createAdminUser(): User
    {
        $email = 'admin-' . bin2hex(random_bytes(4)) . '@example.com';
        $user = User::createByOperator(
            new Email($email),
            password_hash('password', PASSWORD_BCRYPT),
            'Admin User',
            ['ROLE_USER', 'ROLE_ADMIN'],
        );
        $this->userRepository->save($user);

        return $user;
    }

    protected function createSuperAdminUser(): User
    {
        $email = 'sa-' . bin2hex(random_bytes(4)) . '@example.com';
        $user = User::createByOperator(
            new Email($email),
            password_hash('password', PASSWORD_BCRYPT),
            'Super Admin',
            ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'],
        );
        $this->userRepository->save($user);

        return $user;
    }

    protected function authenticatedRequest(string $method, string $uri, User $user, array $content = []): Response
    {
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_Test_User_Id' => $user->getId()->toString(),
        ];

        if ($content !== []) {
            $this->client->request($method, $uri, [], [], $headers, json_encode($content, JSON_THROW_ON_ERROR));
        } else {
            $this->client->request($method, $uri, [], [], $headers);
        }

        return $this->client->getResponse();
    }

    protected function anonymousRequest(string $method, string $uri, array $content = []): Response
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];

        if ($content !== []) {
            $this->client->request($method, $uri, [], [], $headers, json_encode($content, JSON_THROW_ON_ERROR));
        } else {
            $this->client->request($method, $uri, [], [], $headers);
        }

        return $this->client->getResponse();
    }

    protected function assertJsonResponse(Response $response, int $expectedStatus, ?string $expectedKey = null): array
    {
        $this->assertSame($expectedStatus, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        if ($expectedKey !== null) {
            $this->assertArrayHasKey($expectedKey, $data, "Response missing key '{$expectedKey}'");
        }

        return $data;
    }
}
