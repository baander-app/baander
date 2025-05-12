<?php

namespace Baander\Transcoder\Transcoder;

use Amp\Process\Process;
use Monolog\Logger;
use function Amp\async;
use function Amp\ByteStream\getStderr;
use function Amp\ByteStream\getStdout;
use function Amp\ByteStream\pipe;

class TranscodeProcess
{
    private ?Process $process = null;

    public function __construct(
        public TranscodeArguments $command,
        private Logger            $logger,
    )
    {

    }

    public function run()
    {
        $this->process = Process::start(
            command: $this->command->getCommand(),
        );

        async(fn() => pipe($this->process->getStdout(), getStdout()));
        async(fn() => pipe($this->process->getStderr(), getStderr()));

        // exit code
        return $this->process->join();
    }
}