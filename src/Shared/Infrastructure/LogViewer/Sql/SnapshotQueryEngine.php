<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LogViewer\Sql;

use SQLite3;

/** Runs only inside the disposable SQL child, without the application kernel. */
final class SnapshotQueryEngine
{
    private const array COLUMNS = ['id', 'source_id', 'timestamp', 'level', 'channel', 'message', 'context', 'extra', 'raw', 'correlation_id', 'node', 'service', 'stream', 'truncated'];
    private const array KEYS = ['id', 'sourceId', 'timestamp', 'level', 'channel', 'message', 'context', 'extra', 'raw', 'correlationId', 'node', 'service', 'stream', 'truncated'];
    private const array FUNCTIONS = ['abs', 'avg', 'char', 'coalesce', 'concat', 'concat_ws', 'count', 'cume_dist', 'date', 'datetime', 'dense_rank', 'first_value', 'format', 'glob', 'group_concat', 'hex', 'if', 'ifnull', 'iif', 'instr', 'json', 'json_array', 'json_array_length', 'json_error_position', 'json_extract', 'json_group_array', 'json_group_object', 'json_insert', 'json_object', 'json_patch', 'json_quote', 'json_remove', 'json_replace', 'json_set', 'json_type', 'json_valid', 'julianday', 'lag', 'last_value', 'lead', 'length', 'like', 'likelihood', 'likely', 'lower', 'ltrim', 'max', 'min', 'nth_value', 'ntile', 'nullif', 'percent_rank', 'printf', 'quote', 'rank', 'replace', 'round', 'row_number', 'rtrim', 'sign', 'strftime', 'string_agg', 'substr', 'substring', 'sum', 'time', 'timediff', 'total', 'trim', 'typeof', 'unicode', 'unixepoch', 'unlikely', 'upper', '->', '->>'];

    /** @param list<array<string, mixed>> $records
     * @return array{columns:list<string>,rows:list<list<mixed>>,truncated:bool}
     */
    public function run(string $sql, array $records, string $path): array
    {
        SqlStatement::validate($sql);
        $db = new SQLite3($path);
        $db->enableExceptions(true);
        $db->exec('PRAGMA journal_mode=OFF');
        $db->exec('PRAGMA temp_store=MEMORY');
        $db->exec('CREATE TABLE logs ('.implode(',', array_map(static fn (string $column): string => $column.($column === 'truncated' ? ' INTEGER' : ' TEXT'), self::COLUMNS)).')');
        $db->exec('BEGIN');
        $insert = $db->prepare('INSERT INTO logs VALUES ('.implode(',', array_fill(0, count(self::COLUMNS), '?')).')');
        foreach ($records as $record) {
            foreach (self::KEYS as $index => $key) {
                $value = $record[$key] ?? null;
                if ($key === 'context' || $key === 'extra') {
                    $value = json_encode($value ?? [], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                }
                $insert->bindValue($index + 1, $value, $key === 'truncated' ? SQLITE3_INTEGER : ($value === null ? SQLITE3_NULL : SQLITE3_TEXT));
            }
            $insert->execute()->finalize();
            $insert->reset();
        }
        $insert->close();
        $db->exec('COMMIT');
        $db->close();
        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $db->enableExceptions(true);
        $db->exec('PRAGMA temp_store=MEMORY');
        $db->setAuthorizer(static function (int $action, ?string $first, ?string $second, ?string $database): int {
            return match ($action) {
                SQLite3::SELECT, SQLite3::RECURSIVE => SQLite3::OK,
                // SQLite reports CTE-only reads with a null database name.
                SQLite3::READ => $database === null || ($database === 'main' && $first === 'logs') ? SQLite3::OK : SQLite3::DENY,
                SQLite3::FUNCTION => in_array(strtolower($second ?? ''), self::FUNCTIONS, true) ? SQLite3::OK : SQLite3::DENY,
                default => SQLite3::DENY,
            };
        });
        $result = $db->query($sql);
        $columns = [];
        for ($i = 0; $i < $result->numColumns(); ++$i) {
            $columns[] = $result->columnName($i);
        }
        $reply = ['columns' => $columns, 'rows' => [], 'truncated' => false];
        $bytes = strlen(json_encode($reply, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        if ($bytes > 4194000) {
            throw new \InvalidArgumentException('SQL column names exceed the result limit.');
        }
        while (($row = $result->fetchArray(SQLITE3_NUM)) !== false) {
            $rowBytes = strlen(json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) + 1;
            if (count($reply['rows']) >= 1000 || $bytes + $rowBytes > 4194000) {
                $reply['truncated'] = true;
                break;
            }
            $reply['rows'][] = $row;
            $bytes += $rowBytes;
        }
        $result->finalize();
        $db->close();

        return $reply;
    }
}
