<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console\Log;

use App\Shared\Application\LogViewer\LogQuery;
use App\Shared\Application\LogViewer\LogSqlQuery;
use App\Shared\Application\LogViewer\LogViewerInterface;
use App\Shared\Application\LogViewer\TerminalLogGuardInterface;
use App\Shared\Application\Port\SleeperInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class LogTerminalViewer
{
    private bool $stopped = false;
    private ?string $savedMode = null;
    private bool $active = false;

    public function __construct(private readonly LogViewerInterface $viewer, private readonly SleeperInterface $sleeper, private readonly TerminalLogGuardInterface $guard)
    {
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function run(LogQuery $filter, OutputInterface $output): int
    {
        $this->stopped = false;
        $data = $this->viewer->entries($filter);
        $selected = 0;
        $panel = null;
        $prompt = null;
        $value = '';
        $status = '';
        $following = false;
        $allowPartial = false;
        $nextRefresh = microtime(true) + 5;
        $decoder = new LogTerminalInput();
        $this->savedMode = trim((string) shell_exec('stty -g 2>/dev/null'));
        if ($this->savedMode === '') {
            throw new \RuntimeException('Cannot read terminal mode. Use --plain.');
        }
        $guardStarted = false;
        try {
            $this->guard->begin();
            $guardStarted = true;
            $this->active = true;
            shell_exec('stty -icanon -echo min 0 time 0 2>/dev/null');
            $output->write("\033[?1049h\033[?25l", false, OutputInterface::OUTPUT_RAW);
            $restore = function () use ($output): void { $this->restore($output); };
            register_shutdown_function($restore);
            while (!$this->stopped) {
                [$height, $width] = $this->dimensions();
                $items = $data['items'] ?? $data['rows'] ?? [];
                $lastIndex = max(0, $panel !== null ? count(explode("\n", $panel)) - 1 : count($items) - 1);
                $selected = max(0, min($selected, $lastIndex));
                $lines = ['Baander logs | '.($following ? 'FOLLOW' : 'paused').' | '.count($items).' rows | partial SQL '.($allowPartial ? 'allowed' : 'refused')];
                if ($panel !== null) {
                    $lines = array_merge($lines, array_slice(explode("\n", $panel), $selected, max(1, $height - 4)));
                } else {
                    $start = max(0, $selected - max(1, $height - 5) + 1);
                    foreach (array_slice($items, $start, max(1, $height - 4), true) as $index => $item) {
                        $line = isset($item['message']) ? implode(' ', [$item['timestamp'] ?? '', $item['level'] ?? '', $item['service'] ?? '', $item['message']]) : json_encode($item, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                        $lines[] = ($index === $selected ? '> ' : '  ').$line;
                    }
                }
                $coverage = $data['coverage'] ?? [];
                $lines[] = 'Coverage: '.json_encode($coverage, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $lines[] = $status;
                $lines[] = $prompt !== null ? $prompt.'> '.$value : 'j/k move  Enter detail  / search  s sources  f levels  : SQL  ] next page  F follow  ? help  q quit';
                $lines = array_slice($lines, 0, $height);
                $output->write("\033[H\033[2J".implode("\r\n", array_map(static fn (string $line): string => LogTerminalText::clip($line, $width), $lines)), false, OutputInterface::OUTPUT_RAW);
                $read = [STDIN];
                $write = $except = [];
                $ready = @stream_select($read, $write, $except, 0, 100000);
                if ($ready === false) {
                    continue;
                }
                if ($ready > 0) {
                    $bytes = fread(STDIN, 4096);
                    if ($bytes === false || ($bytes === '' && feof(STDIN))) {
                        break;
                    }
                    foreach ($decoder->feed($bytes ?: '') as $key) {
                        if ($key === "\003" || $key === "\004") {
                            $this->stop();
                            break;
                        }
                        try {
                            if ($prompt !== null) {
                                if ($key === "\007") {
                                    $prompt = null;
                                    continue;
                                }
                                if ($key === "\r" || $key === "\n") {
                                    if ($prompt === 'SQL') {
                                        $args = get_object_vars($filter);
                                        $args['cursor'] = null;
                                        $data = $this->viewer->query(new LogSqlQuery($value, $allowPartial, new LogQuery(...$args)));
                                    } else {
                                        $args = get_object_vars($filter);
                                        $args['cursor'] = null;
                                        $args[$prompt] = in_array($prompt, ['sources', 'levels', 'channels'], true) ? self::selections($value) : ($prompt === 'search' ? $value : ($value === '' ? null : $value));
                                        $filter = new LogQuery(...$args);
                                        $data = $this->viewer->entries($filter);
                                    }
                                    $selected = 0;
                                    $panel = null;
                                    $prompt = null;
                                    $status = '';
                                } elseif ($key === "\177" || $key === "\010") {
                                    $value = mb_substr($value, 0, max(0, mb_strlen($value) - 1));
                                } elseif (strlen($value) < 65536 && preg_match('/^[^\p{C}]+$/u', $key) === 1) {
                                    $value .= $key;
                                }
                                continue;
                            }
                            $pageSize = max(1, $height - 5);
                            match ($key) {
                                'q' => $this->stop(),
                                'j', 'down' => ++$selected,
                                'k', 'up' => $selected = max(0, $selected - 1),
                                'page-down' => $selected += $pageSize,
                                'page-up' => $selected = max(0, $selected - $pageSize),
                                'g', 'home' => $selected = 0,
                                'G', 'end' => $selected = $panel !== null ? max(0, count(explode("\n", $panel)) - $pageSize) : max(0, count($items) - 1),
                                'F' => $following = !$following,
                                'a' => $allowPartial = !$allowPartial,
                                default => null,
                            };
                            if ($key === '?' || $key === 'h') {
                                $panel = "Navigation: arrows or j/k; PageUp/PageDown; g/G first/last\nEnter: record details; b: return to records; ]: next cursor page\n/: literal search; n/N: next/previous matching message\ns: list sources then enter comma-separated source IDs (empty selects all)\nf: comma-separated levels; c: comma-separated channels (match any); r: correlation ID\nt/u: since/until RFC 3339 timestamp (empty clears)\n: read-only SQL; a: toggle explicit partial SQL acceptance\nF: follow current filters every five seconds; q: quit\nCtrl-C, Ctrl-D and SIGTERM exit and restore the terminal.";
                                $selected = 0;
                            } elseif ($key === 'b') {
                                $panel = null;
                                $selected = 0;
                            } elseif (in_array($key, ['/', 's', 'f', 'c', 'r', 't', 'u', ':'], true)) {
                                $following = false;
                                $prompt = ['/' => 'search', 's' => 'sources', 'f' => 'levels', 'c' => 'channels', 'r' => 'correlationId', 't' => 'since', 'u' => 'until', ':' => 'SQL'][$key];
                                $value = '';
                                if ($key === 's') {
                                    $panel = AdminCommandSupport::prettyJson($this->viewer->sources());
                                    $selected = 0;
                                }
                            } elseif ($key === "\r" || $key === "\n") {
                                if (isset($items[$selected]['id'])) {
                                    $panel = AdminCommandSupport::prettyJson($this->viewer->detail($items[$selected]['id']));
                                    $selected = 0;
                                }
                            } elseif ($key === ']' && isset($data['nextCursor'])) {
                                $args = get_object_vars($filter);
                                $args['cursor'] = $data['nextCursor'];
                                $filter = new LogQuery(...$args);
                                $data = $this->viewer->entries($filter);
                                $selected = 0;
                            } elseif (($key === 'n' || $key === 'N') && $filter->search !== '' && $items !== []) {
                                for ($step = 1; $step <= count($items); ++$step) {
                                    $candidate = ($selected + ($key === 'n' ? $step : -$step) + count($items)) % count($items);
                                    if (str_contains(mb_strtolower((string) ($items[$candidate]['message'] ?? '')), mb_strtolower($filter->search))) {
                                        $selected = $candidate;
                                        break;
                                    }
                                }
                            }
                        } catch (Throwable $error) {
                            $status = $error->getMessage();
                            $prompt = null;
                        }
                    }
                }
                if ($following && microtime(true) >= $nextRefresh) {
                    $args = get_object_vars($filter);
                    $args['cursor'] = null;
                    $data = $this->viewer->entries(new LogQuery(...$args));
                    $nextRefresh = microtime(true) + 5;
                }
                $this->sleeper->sleep(0.02);
            }
        } finally {
            $this->restore($output);
            if ($guardStarted) {
                $this->guard->end();
            }
        }

        return 0;
    }

    /** @return list<string> */
    private static function selections(string $value): array
    {
        return array_map(trim(...), explode(',', $value))
            |> array_filter(...)
            |> array_values(...);
    }

    /** @return array{int, int} */
    private function dimensions(): array
    {
        $size = trim((string) shell_exec('stty size 2>/dev/null'));
        if (preg_match('/^(\d+) (\d+)$/', $size, $match) === 1) {
            return [max(5, (int) $match[1]), max(10, (int) $match[2])];
        }

        return [24, 80];
    }

    private function restore(OutputInterface $output): void
    {
        if (!$this->active) {
            return;
        }
        $this->active = false;
        if ($this->savedMode !== null) {
            shell_exec('stty '.escapeshellarg($this->savedMode).' 2>/dev/null');
        }
        $output->write("\033[?25h\033[?1049l", false, OutputInterface::OUTPUT_RAW);
    }
}
