<?php

namespace App\Modules\Transcoder;

use Baander\Transcoder\TranscoderContext;

class TranscoderContextFactory
{
    public static function create(): TranscoderContext
    {
        $logger = new \Monolog\Logger('TranscoderLogger');

        return new TranscoderContext(
            ffmpegPath: config('ffmpeg.ffmpeg.binaries'),
            ffprobePath: config('ffmpeg.ffprobe.binaries'),
            transcodeOutputPath: config('ffmpeg.temporary_files_root'),
            readyTimeOut: config('transcoder.ready_time_out'),
            transcodeTimeOut: config('transcoder.transcode_time_out'),
            logger: $logger,
            transcoderLogfilePath: config('transcoder.log_file_path'),
            redisHost: config('database.redis.transcodes.host'),
            redisPassword: config('database.redis.transcodes.password'),
            redisPort: config('database.redis.transcodes.port'),
            redisDb: config('database.redis.transcodes.database'),
        );
    }
}