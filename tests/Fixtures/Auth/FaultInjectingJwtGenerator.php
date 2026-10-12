<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Domain\Model\OAuth\AccessToken;
use RuntimeException;

/**
 * The production signer behind the IssuanceFaults flags: it can fail once, or stall
 * until the block flag is removed. Signing is the last step of an issuing transaction
 * (flush, metadata, sign, commit), so a stalled signer holds every lock the transaction
 * took while another session presents the same token.
 */
final class FaultInjectingJwtGenerator implements JwtGeneratorInterface
{
    private const float BLOCK_DEADLINE_SECONDS = 30.0;

    public function __construct(private readonly JwtGeneratorInterface $inner)
    {
    }

    public function generate(AccessToken $accessToken, ?string $dpopJkt = null): string
    {
        $directory = IssuanceFaults::directory();
        if ($directory !== null) {
            if (IssuanceFaults::consume($directory, IssuanceFaults::SIGN_FAIL_ONCE)) {
                throw new RuntimeException('injected signing failure');
            }
            if (IssuanceFaults::isSet($directory, IssuanceFaults::SIGN_BLOCK)) {
                touch(IssuanceFaults::path($directory, IssuanceFaults::SIGN_BLOCKED));
                $deadline = microtime(true) + self::BLOCK_DEADLINE_SECONDS;
                while (IssuanceFaults::isSet($directory, IssuanceFaults::SIGN_BLOCK)) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('The signing block was not released.');
                    }
                    usleep(5000);
                }
            }
        }

        return $this->inner->generate($accessToken, $dpopJkt);
    }
}
