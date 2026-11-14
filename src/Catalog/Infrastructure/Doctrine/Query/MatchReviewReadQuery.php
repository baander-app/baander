<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Query;

use App\Catalog\Application\Query\MusicBrainzMatch\MatchReview;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchReviewPage;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchReviewReadInterface;
use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Catalog\Domain\ValueObject\AlbumMatchStatus;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Domain\Model\Cursor;
use App\Shared\Domain\Model\CursorDirection;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Infrastructure\Doctrine\Type\InstantType;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;

/**
 * Pages through the album_match_states rows in review with one SQL statement per page. The table
 * is keyed by its album, so its rows are read with SQL and its entity is never loaded
 * (AlbumMatchStateRepository says why).
 *
 * A cursor carries the last album's check time with its microseconds, as InstantType writes it,
 * and the album's public ID, which orders albums checked in the same microsecond.
 */
final readonly class MatchReviewReadQuery implements MatchReviewReadInterface
{
    private const string SELECT = <<<'SQL'
        SELECT a.public_id, a.title, m.reason, m.checked_at, m.best_score
          FROM album_match_states m
          JOIN albums a ON a.id = m.album_id
         WHERE m.state = :state
        SQL;
    private const string AFTER_CURSOR = ' AND (m.checked_at, a.public_id) < (:checkedAt, :publicId)';
    private const string ORDER = ' ORDER BY m.checked_at DESC, a.public_id DESC LIMIT :rows';

    public function __construct(
        private Connection $connection,
        private CursorCodec $cursorCodec,
    ) {
    }

    #[\Override]
    public function page(?string $cursor, int $limit): MatchReviewPage
    {
        $after = $cursor === null ? null : $this->position($cursor);
        $parameters = ['state' => AlbumMatchStatus::NeedsReview->value, 'rows' => $limit + 1];
        $types = ['rows' => ParameterType::INTEGER];
        if ($after !== null) {
            $parameters += ['checkedAt' => $after['checkedAt'], 'publicId' => $after['publicId']];
            $types += ['checkedAt' => Types::DATETIME_IMMUTABLE];
        }

        $rows = $this->connection->fetchAllAssociative(
            self::SELECT . ($after === null ? '' : self::AFTER_CURSOR) . self::ORDER,
            $parameters,
            $types,
        );
        $items = array_map($this->review(...), array_slice($rows, 0, $limit));
        $hasNextPage = count($rows) > $limit;
        $last = array_last($items);

        return new MatchReviewPage(
            items: $items,
            nextCursor: $hasNextPage && $last !== null ? $this->cursorAfter($last) : null,
            hasNextPage: $hasNextPage,
            perPage: $limit,
        );
    }

    /** @param array<string, mixed> $row */
    private function review(array $row): MatchReview
    {
        $checkedAt = $this->connection->convertToPHPValue($row['checked_at'], Types::DATETIME_IMMUTABLE);
        if (!$checkedAt instanceof \DateTimeImmutable || $row['reason'] === null) {
            throw new \UnexpectedValueException(sprintf('The review of album "%s" has no reason or no check time.', (string) $row['public_id']));
        }

        return new MatchReview(
            albumPublicId: (string) $row['public_id'],
            title: (string) $row['title'],
            reason: AlbumMatchReviewReason::from((string) $row['reason']),
            checkedAt: $checkedAt,
            bestScore: $row['best_score'] === null ? null : (int) $row['best_score'],
        );
    }

    private function cursorAfter(MatchReview $review): string
    {
        return $this->cursorCodec->encode(Cursor::create(CursorDirection::Next, [
            'sort' => $review->checkedAt->format(InstantType::WRITE_FORMAT),
            'id' => $review->albumPublicId,
        ]));
    }

    /**
     * @return array{checkedAt: \DateTimeImmutable, publicId: string}
     *
     * @throws InvalidInputException when the cursor is not one this list issued
     */
    private function position(string $cursor): array
    {
        $values = $this->cursorCodec->decode($cursor)?->getValues() ?? [];
        $sort = $values['sort'] ?? null;
        $id = $values['id'] ?? null;
        $checkedAt = is_string($sort) ? \DateTimeImmutable::createFromFormat(InstantType::WRITE_FORMAT, $sort) : false;
        if ($checkedAt === false || !is_string($id) || !self::isPublicId($id)) {
            throw new InvalidInputException('cursor is not a cursor of this list; request the first page again without a cursor.');
        }

        return ['checkedAt' => $checkedAt, 'publicId' => $id];
    }

    private static function isPublicId(string $id): bool
    {
        try {
            PublicId::fromString($id);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
    }
}
