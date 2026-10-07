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
        $this->user = User::register(new Email('test@baander.app'), 'hashed', 'Alice');
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

    public function testFirstPollIsNeverTooSoon(): void
    {
        $code = DeviceCode::create($this->client, 'BCDF-GHJK', '/verify', interval: 5);

        $this->assertFalse($code->recordPoll(new \DateTimeImmutable('@1000')));
        $this->assertSame(1000, $code->getLastPolledAt()?->getTimestamp());
        $this->assertSame(5, $code->getInterval());
    }

    public function testPollWithinTheIntervalSlowsDownByFiveSeconds(): void
    {
        $code = DeviceCode::create($this->client, 'BCDF-GHJK', '/verify', interval: 5);
        $code->recordPoll(new \DateTimeImmutable('@1000'));

        $this->assertTrue($code->recordPoll(new \DateTimeImmutable('@1004')));
        $this->assertSame(10, $code->getInterval());

        // The longer interval applies to the following polls (RFC 8628 section 3.5).
        $this->assertTrue($code->recordPoll(new \DateTimeImmutable('@1012')));
        $this->assertSame(15, $code->getInterval());
        $this->assertFalse($code->recordPoll(new \DateTimeImmutable('@1027')));
        $this->assertSame(15, $code->getInterval());
    }

    public function testGeneratedUserCodesUseTheConsonantFormat(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->assertMatchesRegularExpression('/^[BCDFGHJKLMNPQRSTVWXZ]{4}-[BCDFGHJKLMNPQRSTVWXZ]{4}$/', DeviceCode::generateUserCode());
        }
    }

    public function testUserCodeNormalizationIgnoresCaseSpacesAndDashes(): void
    {
        $this->assertSame('BCDF-GHJK', DeviceCode::normalizeUserCode('bcdf ghjk'));
        $this->assertSame('BCDF-GHJK', DeviceCode::normalizeUserCode('BCDFGHJK'));
        $this->assertSame('BCDF-GHJK', DeviceCode::normalizeUserCode(' bcdf-ghjk '));
        $this->assertNull(DeviceCode::normalizeUserCode('ABCD-EFGH'), 'Vowels are not in the alphabet.');
        $this->assertNull(DeviceCode::normalizeUserCode('BCDF-GHJ'));
    }

    public function testApprovingAnExpiredCodeThrows(): void
    {
        $code = DeviceCode::create($this->client, 'BCDF-GHJK', '/verify', ttl: new \DateInterval('PT0S'));
        usleep(1000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expired');

        $code->approve($this->user);
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
