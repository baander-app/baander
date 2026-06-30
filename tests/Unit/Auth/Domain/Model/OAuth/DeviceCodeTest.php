<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Model;

use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\DeviceCode;
use App\Auth\Domain\Model\OAuth\DeviceCodeState;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Shared\Domain\Model\Email;
use DateInterval;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DeviceCodeTest extends TestCase
{
    private Client $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = Client::create('Test', []);
        $this->user = User::register(new Email('test@example.com'), 'hashed', 'Alice');
    }

    public function testCreate(): void
    {
        $code = DeviceCode::create($this->client, 'ABCD-EFGH', '/verify');

        $this->assertSame('ABCD-EFGH', $code->getUserCode());
        $this->assertSame('/verify', $code->getVerificationUri());
        $this->assertNull($code->getVerificationUriComplete());
        $this->assertNull($code->getUser());
        $this->assertTrue($code->isPending());
        $this->assertFalse($code->isApproved());
        $this->assertFalse($code->isDenied());
        $this->assertNull($code->getExpiresAt());
        $this->assertFalse($code->isExpired());
    }

    public function testCreateWithAllParams(): void
    {
        $code = DeviceCode::create(
            client: $this->client,
            userCode: 'ABCD',
            verificationUri: '/verify',
            verificationUriComplete: '/verify?code=ABCD',
            scopes: [new Scope('profile')],
            ttl: new DateInterval('PT15M'),
            interval: 10,
        );

        $this->assertSame('/verify?code=ABCD', $code->getVerificationUriComplete());
        $this->assertCount(1, $code->getScopes());
        $this->assertSame(10, $code->getInterval());
    }

    public function testApprove(): void
    {
        $code = DeviceCode::create($this->client, 'ABCD', '/verify');

        $code->approve($this->user);

        $this->assertTrue($code->isApproved());
        $this->assertFalse($code->isPending());
        $this->assertSame($this->user, $code->getUser());
    }

    public function testApproveIdempotent(): void
    {
        $code = DeviceCode::create($this->client, 'ABCD', '/verify');
        $code->approve($this->user);
        $before = $code->getUpdatedAt();

        $code->approve($this->user);

        $this->assertEquals($before, $code->getUpdatedAt());
    }

    public function testApproveDeniedThrows(): void
    {
        $code = DeviceCode::create($this->client, 'ABCD', '/verify');
        $code->deny();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been denied');

        $code->approve($this->user);
    }

    public function testDeny(): void
    {
        $code = DeviceCode::create($this->client, 'ABCD', '/verify');

        $code->deny();

        $this->assertTrue($code->isDenied());
        $this->assertFalse($code->isPending());
    }

    public function testDenyIdempotent(): void
    {
        $code = DeviceCode::create($this->client, 'ABCD', '/verify');
        $code->deny();
        $before = $code->getUpdatedAt();

        $code->deny();

        $this->assertEquals($before, $code->getUpdatedAt());
    }

    public function testDenyApprovedThrows(): void
    {
        $code = DeviceCode::create($this->client, 'ABCD', '/verify');
        $code->approve($this->user);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been approved');

        $code->deny();
    }

    public function testMarkPolled(): void
    {
        $code = DeviceCode::create($this->client, 'ABCD', '/verify');

        $code->markPolled();

        $this->assertNotNull($code->getLastPolledAt());
    }

    public function testReconstitute(): void
    {
        $now = new \DateTimeImmutable();

        $code = DeviceCode::reconstitute(new DeviceCodeState(
            id: \App\Shared\Domain\Model\Uuid::v4(),
            deviceCode: \App\Auth\Domain\Model\OAuth\TokenId::generate(),
            userCode: 'CODE',
            user: null,
            client: $this->client,
            scopes: [],
            verificationUri: '/v',
            verificationUriComplete: null,
            expiresAt: null,
            interval: 5,
            lastPolledAt: null,
            createdAt: $now,
            updatedAt: $now,
            approved: true,
            denied: false,
        ));

        $this->assertTrue($code->isApproved());
        $this->assertSame('CODE', $code->getUserCode());
    }

    public function testReconstituteWithConsumedAt(): void
    {
        $now = new \DateTimeImmutable();
        $consumedAt = new \DateTimeImmutable('+1 second');

        $code = DeviceCode::reconstitute(new DeviceCodeState(
            id: \App\Shared\Domain\Model\Uuid::v4(),
            deviceCode: \App\Auth\Domain\Model\OAuth\TokenId::generate(),
            userCode: 'CODE',
            user: $this->user,
            client: $this->client,
            scopes: [],
            verificationUri: '/v',
            verificationUriComplete: null,
            expiresAt: null,
            interval: 5,
            lastPolledAt: null,
            createdAt: $now,
            updatedAt: $now,
            approved: true,
            denied: false,
            consumedAt: $consumedAt,
        ));

        $this->assertTrue($code->isConsumed());
        $this->assertEquals($consumedAt, $code->getConsumedAt());
    }

    public function testConsumeApproved(): void
    {
        $code = DeviceCode::create($this->client, 'ABCD', '/verify');
        $code->approve($this->user);

        $before = new \DateTimeImmutable();
        $code->consume();
        $after = new \DateTimeImmutable();

        $this->assertTrue($code->isConsumed());
        $this->assertNotNull($code->getConsumedAt());
        $this->assertGreaterThanOrEqual($before, $code->getConsumedAt());
        $this->assertLessThanOrEqual($after, $code->getConsumedAt());
    }

    public function testConsumeAlreadyConsumedThrows(): void
    {
        $code = DeviceCode::create($this->client, 'ABCD', '/verify');
        $code->approve($this->user);
        $code->consume();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been consumed');

        $code->consume();
    }

    public function testConsumeUnapprovedThrows(): void
    {
        $code = DeviceCode::create($this->client, 'ABCD', '/verify');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not been approved');

        $code->consume();
    }

    public function testConsumeDeniedThrows(): void
    {
        $code = DeviceCode::create($this->client, 'ABCD', '/verify');
        $code->deny();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not been approved');

        $code->consume();
    }
}
