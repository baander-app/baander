<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\PurgeExpiredOAuthCodesCommand;
use App\Auth\Application\DTO\PurgedOAuthCodesDTO;
use App\Auth\Domain\Repository\OAuth\AuthCodeRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\DeviceCodeRepositoryInterface;
use DateInterval;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes authorization codes and device codes once they have been expired for an hour.
 *
 * The hour of grace keeps an expired device code long enough for a device that
 * is still polling to receive expired_token (RFC 8628 section 3.5) rather than
 * invalid_grant. Expired authorization codes are unusable either way. Codes
 * without an expiry never expire and are kept.
 */
final readonly class PurgeExpiredOAuthCodesHandler
{
    public const string GRACE = 'PT1H';

    public function __construct(
        private AuthCodeRepositoryInterface $authCodeRepository,
        private DeviceCodeRepositoryInterface $deviceCodeRepository,
        private ClockInterface $clock,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(PurgeExpiredOAuthCodesCommand $command): PurgedOAuthCodesDTO
    {
        $cutoff = $this->clock->now()->sub(new DateInterval(self::GRACE));

        return new PurgedOAuthCodesDTO(
            authorizationCodes: $this->authCodeRepository->deleteExpiredBefore($cutoff),
            deviceCodes: $this->deviceCodeRepository->deleteExpiredBefore($cutoff),
            expiredBefore: $cutoff,
        );
    }
}
