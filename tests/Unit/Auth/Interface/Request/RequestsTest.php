<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Request;

use App\Auth\Interface\Request\User\CreateClientRequest;
use App\Auth\Interface\Request\Totp\DisableTotpRequest;
use App\Auth\Interface\Request\Totp\EnableTotpRequest;
use App\Auth\Interface\Request\User\LoginRequest;
use App\Auth\Interface\Request\Passkey\RegisterPasskeyRequest;
use App\Auth\Interface\Request\User\RegisterRequest;
use App\Auth\Interface\Request\OAuth\RefreshTokenRequest;
use App\Auth\Interface\Request\User\UpdateProfileRequest;
use App\Auth\Interface\Request\Passkey\VerifyPasskeyChallengeRequest;
use PHPUnit\Framework\TestCase;

final class RequestsTest extends TestCase
{
    public function testRegisterRequestConstructor(): void
    {
        $req = new RegisterRequest(name: 'Alice', email: 'a@b.com', password: '12345678');

        $this->assertSame('Alice', $req->name);
        $this->assertSame('a@b.com', $req->email);
        $this->assertSame('12345678', $req->password);
    }

    public function testRegisterRequestDefaults(): void
    {
        $req = new RegisterRequest();

        $this->assertSame('', $req->name);
        $this->assertSame('', $req->email);
        $this->assertSame('', $req->password);
    }

    public function testLoginRequestConstructor(): void
    {
        $req = new LoginRequest(email: 'a@b.com', password: 'pw');

        $this->assertSame('a@b.com', $req->email);
        $this->assertSame('pw', $req->password);
        $this->assertNull($req->totpCode);
    }

    public function testLoginRequestWithTotpCode(): void
    {
        $req = new LoginRequest(email: 'a@b.com', password: 'pw', totpCode: '123456');

        $this->assertSame('a@b.com', $req->email);
        $this->assertSame('pw', $req->password);
        $this->assertSame('123456', $req->totpCode);
    }

    public function testUpdateProfileRequestConstructor(): void
    {
        $req = new UpdateProfileRequest(name: 'New Name');

        $this->assertSame('New Name', $req->name);
    }

    public function testEnableTotpRequestConstructor(): void
    {
        $req = new EnableTotpRequest(code: '123456');

        $this->assertSame('123456', $req->code);
    }

    public function testDisableTotpRequestConstructor(): void
    {
        $req = new DisableTotpRequest(code: '123456');

        $this->assertSame('123456', $req->code);
    }

    public function testRegisterPasskeyRequestConstructor(): void
    {
        $req = new RegisterPasskeyRequest(challengeKey: 'ck', response: ['data'], name: 'Key');

        $this->assertSame('ck', $req->challengeKey);
        $this->assertSame(['data'], $req->response);
        $this->assertSame('Key', $req->name);
    }

    public function testRegisterPasskeyRequestDefaults(): void
    {
        $req = new RegisterPasskeyRequest();

        $this->assertSame('', $req->challengeKey);
        $this->assertNull($req->response);
        $this->assertSame('Passkey', $req->name);
    }

    public function testVerifyPasskeyChallengeRequestConstructor(): void
    {
        $req = new VerifyPasskeyChallengeRequest(challengeKey: 'ck', response: [], userId: 'u1');

        $this->assertSame('ck', $req->challengeKey);
        $this->assertSame([], $req->response);
        $this->assertSame('u1', $req->userId);
    }

    public function testVerifyPasskeyChallengeRequestDefaults(): void
    {
        $req = new VerifyPasskeyChallengeRequest();

        $this->assertSame('', $req->challengeKey);
        $this->assertNull($req->response);
        $this->assertSame('', $req->userId);
    }

    public function testCreateClientRequestConstructor(): void
    {
        $req = new CreateClientRequest(name: 'My App');

        $this->assertSame('My App', $req->name);
    }

    public function testRefreshTokenRequestConstructor(): void
    {
        $req = new RefreshTokenRequest(refreshToken: 'ref-123');

        $this->assertSame('ref-123', $req->refreshToken);
    }
}
