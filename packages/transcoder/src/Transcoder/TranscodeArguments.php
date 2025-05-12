<?php

namespace Baander\Transcoder\Transcoder;

class TranscodeArguments
{
    private array $arguments = [];

    public function __construct(private readonly string $ffmpegBinaryPath)
    {
    }

    public function setArgument(string $argument)
    {
        $this->arguments[] = $argument;

        return $this;
    }

    public function setNamedArgument(string $argument, string $value)
    {
        $this->arguments[] = $argument . ' ' . $value;

        return $this;
    }

    public function setUniqueArgument(string $argument)
    {
        $value = escapeshellarg($argument);

        $this->arguments = array_filter($this->arguments, fn($item) => $item !== $value);
        $this->arguments[] = $value;

        return $this;
    }

    public function getCommand(): string
    {
        return $this->ffmpegBinaryPath . ' ' . implode(' ', $this->arguments);
    }

    public function setDirectPlayArguments(string $inputFilePath): self
    {
        $this->arguments = []; // Clear existing arguments
        $this->arguments[] = '-i ' . escapeshellarg($inputFilePath); // Input file
        $this->arguments[] = '-c copy'; // Copy streams without transcoding
        $this->arguments[] = '-f matroska'; // Output as MKV container for compatibility

        return $this;
    }

}