<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Platform;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Index;

/**
 * Extends PostgreSQLPlatform to support pgroonga, GIN with operator classes,
 * and WITH options for index creation and introspection.
 *
 * Entity attribute usage:
 *
 *   Pgroonga full-text search:
 *     #[ORM\Index(name: 'idx_songs_title_pgroonga', columns: ['title'], flags: ['pgroonga'],
 *         options: ['with' => "plugins='token_filters/stem', tokenizer='TokenNgram', normalizer='NormalizerAuto', token_filters='TokenFilterStem'"])]
 *
 *   GIN trigram search:
 *     #[ORM\Index(name: 'idx_songs_public_id_trgm', columns: ['public_id'], flags: ['gin'],
 *         options: ['operator_class' => 'gin_trgm_ops'])]
 *
 *   Partial index (native DBAL support):
 *     #[ORM\Index(name: 'idx_albums_cover_image_null', columns: ['id'],
 *         options: ['where' => 'cover_image_id IS NULL'])]
 */
class BaanderPostgreSQLPlatform extends PostgreSQLPlatform
{
    /**
     * Index methods that require USING clause.
     */
    private const USING_METHODS = ['pgroonga', 'gin', 'gist', 'hash', 'spgist', 'brin'];

    public function getCreateIndexSQL(Index $index, string $table): string
    {
        if ($index->isPrimary()) {
            return parent::getCreateIndexSQL($index, $table);
        }

        $columns = $index->getColumns();
        if (count($columns) === 0) {
            return parent::getCreateIndexSQL($index, $table);
        }

        $flags = $index->getFlags();
        $usingMethod = $this->extractUsingMethod($flags);

        if ($usingMethod === null) {
            return parent::getCreateIndexSQL($index, $table);
        }

        $name = $index->getQuotedName($this);
        $unique = $index->isUnique() ? 'UNIQUE ' : '';
        $columnSpec = $this->buildColumnSpec($index);
        $withClause = $this->buildWithClause($index);
        $whereClause = $this->getPartialIndexSQL($index);

        return sprintf(
            'CREATE %sINDEX %s ON %s USING %s (%s)%s%s',
            $unique,
            $name,
            $table,
            $usingMethod,
            $columnSpec,
            $withClause,
            $whereClause,
        );
    }

    public function createSchemaManager(Connection $connection): BaanderPostgreSQLSchemaManager
    {
        return new BaanderPostgreSQLSchemaManager($connection, $this);
    }

    /**
     * @param list<string> $flags
     */
    private function extractUsingMethod(array $flags): ?string
    {
        foreach ($flags as $flag) {
            $lower = strtolower($flag);
            if (in_array($lower, self::USING_METHODS, true)) {
                return $lower;
            }
        }

        return null;
    }

    private function buildColumnSpec(Index $index): string
    {
        $quotedColumns = $index->getQuotedColumns($this);
        $options = $index->getOptions();
        $operatorClass = $options['operator_class'] ?? $options['operatorclass'] ?? null;

        if ($operatorClass !== null && $operatorClass !== '') {
            return implode(', ', array_map(
                static fn(string $col): string => $col . ' ' . $operatorClass,
                $quotedColumns,
            ));
        }

        return implode(', ', $quotedColumns);
    }

    private function buildWithClause(Index $index): string
    {
        $options = $index->getOptions();
        $with = $options['with'] ?? null;

        if ($with === null || $with === '' || $with === []) {
            return '';
        }

        if (is_array($with)) {
            $with = implode(', ', $with);
        }

        return ' WITH (' . $with . ')';
    }
}
