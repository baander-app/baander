<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Application\Port\TotpSecretCipherInterface;
use App\Auth\Domain\Model\User;
use OTPHP\TOTP;

/** Turns TOTP on for a test user directly, so no time step is spent before the test uses a code. */
trait EnrolsTotp
{
    private const string TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    private function enrolTotp(User $user): void
    {
        $cipher = static::getContainer()->get(TotpSecretCipherInterface::class);
        self::assertInstanceOf(TotpSecretCipherInterface::class, $cipher);
        $user->enableTotp($cipher->seal(self::TOTP_SECRET));
        $this->userRepository->save($user);
    }

    private static function currentTotpCode(): string
    {
        return TOTP::create(self::TOTP_SECRET)->now();
    }
}
