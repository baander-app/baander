<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

enum MediaOperation: string
{
    case AudioEncode = 'audio_encode';
    case VideoEncode = 'video_encode';
    case StreamCopy = 'stream_copy';
    case Probe = 'probe';
    case AudioAnalysis = 'audio_analysis';
    case ImageDerivative = 'image_derivative';
    case SubtitleExtraction = 'subtitle_extraction';
}
