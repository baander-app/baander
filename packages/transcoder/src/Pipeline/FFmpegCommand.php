<?php

namespace Baander\Transcoder\Pipeline;

use Psr\Log\LoggerInterface;

final class FFmpegCommand
{
    private array $arguments = [];

    public function __construct(private LoggerInterface $logger)
    {
    }

    public function addArgument(string $name, string $value): self
    {
        $this->arguments[] = trim($name . ' ' . $value);
        return $this;
    }

    public function getCommand(): string
    {
        if (!$this->validate()) {
            throw new \RuntimeException('Invalid FFmpeg command configuration.');
        }

        return implode(' ', $this->arguments);
    }

    private function validate(): bool
    {
        $requiredOptions = ['-i']; // Mandatory options for FFmpeg
        foreach ($requiredOptions as $option) {
            if (!preg_grep('/\b' . preg_quote($option, '/') . '\b/', $this->arguments)) {
                $this->logger->error("Missing mandatory FFmpeg option: $option");
                return false;
            }
        }

        return true;
    }
}