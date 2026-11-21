<?php

declare(strict_types=1);

namespace Baander\MediaMetadata\Reader;

use Baander\MediaMetadata\Model\ExtractedMetadata;
use Psr\Log\LoggerInterface;

/** Raw ADTS AAC; MP4 audio is read by the MP4 container reader. */
final class AacReader
{
    private const array SAMPLE_RATES = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function read(string $path): ExtractedMetadata
    {
        $metadata = new ExtractedMetadata();
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return $metadata;
        }
        try {
            $budget = 16 * 1024 * 1024;
            for ($count = 0; $count < 16; ++$count) {
                $position = ftell($handle);
                $header = fread($handle, 10);
                if (strlen($header) !== 10 || substr($header, 0, 3) !== 'ID3') {
                    fseek($handle, $position);
                    break;
                }
                $sizeBytes = substr($header, 6, 4);
                if (preg_match('/[\x80-\xff]/', $sizeBytes) === 1) {
                    return $metadata->setDuration(null);
                }
                $size = (ord($sizeBytes[0]) << 21) | (ord($sizeBytes[1]) << 14) | (ord($sizeBytes[2]) << 7) | ord($sizeBytes[3]);
                if ($size > $budget || $size > fstat($handle)['size'] - ftell($handle)) {
                    return $metadata->setDuration(null);
                }
                $metadata = (new Id3Reader($this->logger))->readBytes($header . ($size > 0 ? fread($handle, $size) : ''));
                $budget -= $size;
                if (ord($header[3]) === 4 && (ord($header[5]) & 0x10) !== 0) {
                    fseek($handle, 10, SEEK_CUR);
                }
            }
            $header = fread($handle, 7);
            $metadata->setDuration(null);
            if (strlen($header) !== 7 || ord($header[0]) !== 0xff || (ord($header[1]) & 0xf6) !== 0xf0) {
                return $metadata;
            }
            $rateIndex = (ord($header[2]) >> 2) & 0x0f;
            $configuration = ((ord($header[2]) & 1) << 2) | (ord($header[3]) >> 6);
            $frameLength = ((ord($header[3]) & 3) << 11) | (ord($header[4]) << 3) | (ord($header[5]) >> 5);
            $headerSize = (ord($header[1]) & 1) !== 0 ? 7 : 9;
            if (!isset(self::SAMPLE_RATES[$rateIndex]) || $frameLength < $headerSize || $frameLength - 7 > fstat($handle)['size'] - ftell($handle)) {
                return $metadata;
            }
            $metadata->setSampleRate(self::SAMPLE_RATES[$rateIndex]);
            // Configuration zero requires an AAC program configuration element.
            if ($configuration > 0) {
                $metadata->setChannels($configuration === 7 ? 8 : $configuration);
            }
            // Frame-local transport fields do not establish duration or variable bitrate.
        } catch (\Throwable $error) {
            $this->logger->warning('Failed to parse AAC file', ['path' => $path, 'error' => $error->getMessage()]);
        } finally {
            fclose($handle);
        }
        return $metadata->setDuration(null);
    }
}
