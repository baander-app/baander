<?php

namespace Baander\Transcoder;

use Monolog\Logger;

class TranscoderContext
{
    public function __construct(
        // Binaries
        public string $ffmpegPath,
        public string $ffprobePath,
        // Cache config
        public string $transcodeOutputPath,
        // Manager config
        public int $readyTimeOut,
        public int $transcodeTimeOut,
        public Logger $logger,
        public string $transcoderLogfilePath,
        // redis config
        public string $redisHost,
        public ?string $redisPassword,
        public int $redisPort,
        public int $redisDb,
    )
    {
    }
}