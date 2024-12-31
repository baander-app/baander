<?php

namespace App\Packages\LogStreamer;

use Symfony\Component\Process\Process;

class SearchableLogFile
{
    public function __construct(
        public string $path
    )
    {}

    /**
     * Calculates the number of lines in a file.
     *
     * @return int The number of lines in the file.
     */
    public function numberOfLines()
    {
        return (int)$this->execCommand('awk', 'END {print NR}', $this->path);
    }

    /**
     * Retrieves the content of a file starting from a specified line number
     *
     * @param int $lineNumber The line number to start retrieving the content from.
     * @return string The content of the file after the specified line number.
     */
    public function contentAfterLine(int $lineNumber)
    {
        return $this->execCommand('awk', "NR > {$lineNumber}", $this->path);
    }

    private function execCommand(string $command, ...$args)
    {
        $process = new Process(array_merge([$command], $args));
        $process->run();

        return $process->getOutput();
    }
}