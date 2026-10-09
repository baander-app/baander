<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\AdminCliParity;

use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Attribute\CliParityExemption;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller methods for the synthetic AdminCliParityTest cases. The test builds
 * its own route collection; no router loads this class.
 */
final class ParityFixtureController
{
    #[IsGranted('ROLE_ADMIN')]
    public function unmarked(): void
    {
    }

    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:fixture:missing')]
    public function unknownCommand(): void
    {
    }

    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:fixture:documented')]
    public function documented(): void
    {
    }

    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:fixture:undocumented')]
    public function undocumented(): void
    {
    }

    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('messenger:failed:retry')]
    public function frameworkCommand(): void
    {
    }

    #[CliParityExemption('deferred: fixture route was removed')]
    public function removedRoute(): void
    {
    }

    #[IsGranted('ROLE_ADMIN')]
    #[CliParityExemption('deferred: the fixture command comes later')]
    public function deferred(): void
    {
    }

    #[IsGranted('ROLE_ADMIN')]
    #[CliParityExemption("reads the signed-in administrator's own inbox")]
    public function exempt(): void
    {
    }

    public function unguarded(): void
    {
    }

    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function superAdminOnly(): void
    {
    }

    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function authenticated(): void
    {
    }

    #[IsGranted('FIXTURE_UNKNOWN_ATTRIBUTE')]
    public function unclassified(): void
    {
    }
}
