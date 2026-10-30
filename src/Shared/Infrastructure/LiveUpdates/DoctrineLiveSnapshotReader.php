<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LiveUpdates;

use App\Auth\Application\Port\CurrentUserAuthorityReader;
use App\Library\Application\Port\LibraryAudienceReader;
use App\Playlist\Application\Port\PlaylistAudienceReader;
use App\Shared\Application\LiveUpdates\LiveAccessDenied;
use App\Shared\Application\LiveUpdates\LiveFamily;
use App\Shared\Application\LiveUpdates\LiveRevisionStore;
use App\Shared\Application\LiveUpdates\LiveScope;
use App\Shared\Application\LiveUpdates\LiveScopeKind;
use App\Shared\Application\LiveUpdates\LiveSnapshot;
use App\Shared\Application\LiveUpdates\LiveSnapshotReader;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;

final readonly class DoctrineLiveSnapshotReader implements LiveSnapshotReader
{
    public function __construct(
        private Connection $connection,
        private CurrentUserAuthorityReader $authority,
        private LibraryAudienceReader $libraries,
        private PlaylistAudienceReader $playlists,
        private LiveRevisionStore $revisions,
    ) {}

    public function read(Uuid $principal): LiveSnapshot
    {
        if ($this->connection->getTransactionNestingLevel() !== 0) {
            throw new \LogicException('Live snapshots require an idle connection.');
        }
        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $this->connection->executeStatement("SET LOCAL statement_timeout = '500ms'");
            $this->connection->executeStatement("SET LOCAL transaction_timeout = '1s'");
            $authority = $this->authority->read($principal);
            if ($authority === null || $authority->disabled) {
                throw new LiveAccessDenied('The current principal is unavailable.');
            }
            $administrator = $authority->isAdministrator();
            $libraryIds = $this->canonical($this->libraries->readableLibraryIds($principal, $administrator));
            $playlistIds = $this->canonical($this->playlists->readablePlaylistIds($principal, $administrator));
            $families = [];
            $scopes = [];
            foreach (LiveFamily::cases() as $family) {
                $adminFamily = str_starts_with($family->value, 'admin-') || $family === LiveFamily::DeploymentDefinitions;
                if ($adminFamily && !$administrator) {
                    continue;
                }
                $families[] = $family;
                if ($adminFamily) {
                    $scopes[] = new LiveScope($family, LiveScopeKind::Admin, '*');
                    continue;
                }
                $scopes[] = new LiveScope($family, LiveScopeKind::User, $principal->toString());
                if (in_array($family, [LiveFamily::Catalog, LiveFamily::Libraries, LiveFamily::Lyrics], true)) {
                    foreach ($libraryIds as $id) {
                        $scopes[] = new LiveScope($family, LiveScopeKind::Library, $id);
                    }
                }
                if (in_array($family, [LiveFamily::Catalog, LiveFamily::Playlists], true)) {
                    foreach ($playlistIds as $id) {
                        $scopes[] = new LiveScope($family, LiveScopeKind::Playlist, $id);
                    }
                }
                if ($family === LiveFamily::Radio) {
                    $scopes[] = new LiveScope($family, LiveScopeKind::Global, '*');
                }
            }
            $fingerprint = hash('sha256', json_encode([
                'principal' => $principal->toString(),
                'roles' => $this->canonical($authority->roles),
                'libraries' => $libraryIds,
                'playlists' => $playlistIds,
            ], JSON_THROW_ON_ERROR));
            $snapshot = new LiveSnapshot($families, $fingerprint, $this->revisions->revisions($scopes), $this->revisions->health());
            $this->connection->commit();

            return $snapshot;
        } catch (\Throwable $error) {
            try {
                $this->connection->rollBack();
            } catch (\Throwable) {
                $this->connection->close();
            }
            throw $error;
        }
    }

    /** @param list<string> $values
     * @return list<string>
     */
    private function canonical(array $values): array
    {
        $values = array_unique($values);
        sort($values, SORT_STRING);

        return $values;
    }
}
