<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use App\Shared\Application\LogViewer\{LogQuery, LogSqlQuery, LogViewerInterface, TerminalLogGuardInterface};
use App\Shared\Application\Port\SleeperInterface;
use App\Shared\Interface\Console\Log\{LogTerminalViewer, LogViewCommand};
use Symfony\Component\Console\Application;

$viewer = new class implements LogViewerInterface {
    private int $reads = 0;
    private ?LogQuery $initial = null;
    private bool $paged = false;
    public function sources(): array { return ['items' => [['id' => 'archive']]]; }
    public function entries(LogQuery $query): array {
        if (++$this->reads > 1 && getenv('LOG_PTY_THROW') === '1') { throw new RuntimeException('simulated collection failure'); }
        $this->initial ??= $query;
        $this->paged = $this->paged || $query->cursor === 'older-page';
        if (getenv('LOG_PTY_CHANNELS') === '1') {
            return ['items' => [['id' => 'row', 'message' => 'CHANNELS:'.json_encode($query->channels, JSON_THROW_ON_ERROR)]], 'coverage' => ['complete' => true]];
        }
        return ['nextCursor' => 'older-page', 'items' => [['id' => 'row', 'message' => '中文 '.str_repeat('x', 140), 'level' => 'INFO']], 'coverage' => ['complete' => true]];
    }
    public function detail(string $id): array { return ['raw' => 'full message']; }
    public function query(LogSqlQuery $query): array {
        if (getenv('LOG_PTY_SQL') === '1') {
            if ($query->filter->channels !== ['worker', 'scheduler'] || !$this->paged || $query->filter->cursor !== null || $query->filter->fingerprint() !== $this->initial?->fingerprint() || $query->filter->limit !== $this->initial->limit) {
                throw new RuntimeException('SQL must keep filters and clear the browse cursor after paging');
            }
            return ['columns' => ['status'], 'rows' => [['SQL_FILTERS_OK']], 'coverage' => ['complete' => true]];
        }
        return ['columns' => ['count'], 'rows' => [[1]], 'coverage' => ['complete' => true]];
    }
};
$sleeper = new class implements SleeperInterface { public function sleep(float $seconds): void {} };
$guard = new class implements TerminalLogGuardInterface { public function begin(): void {} public function end(): void {} };
$app = new Application();
$app->addCommand(new LogViewCommand($viewer, new LogTerminalViewer($viewer, $sleeper, $guard)));
$app->run();
