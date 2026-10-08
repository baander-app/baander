<?php

declare(strict_types=1);

namespace App\Shared\Interface\Attribute;

/**
 * Records why an admin route has no console counterpart.
 *
 * The reason names the decision behind the exemption. Routes whose command is
 * deferred use one of the constants below; ROADMAP.md lists that deferred work
 * under its Admin/CLI parity item. `tests/Integration/AdminCliParityTest.php`
 * fails when the attribute stays on a method that no route reaches.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class CliParityExemption
{
    /** An admin-only action in the catalog or player UI, outside the admin pages that parity covers. */
    public const string DEFERRED_CATALOG_PLAYER_ACTION = 'deferred: catalog/player admin action; see Admin/CLI parity in ROADMAP.md';

    /** An admin-only API action that no admin page offers. */
    public const string DEFERRED_NO_ADMIN_PAGE = 'deferred: no admin page offers it; see Admin/CLI parity in ROADMAP.md';

    public function __construct(
        public string $reason,
    ) {
    }
}
