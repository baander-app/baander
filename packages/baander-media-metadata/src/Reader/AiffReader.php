<?php

declare(strict_types=1);

namespace Baander\MediaMetadata\Reader;

use Baander\MediaMetadata\Model\ExtractedMetadata;
use Psr\Log\LoggerInterface;

final class AiffReader
{
    private const array TEXT_TAGS = ['NAME' => 'Title', 'AUTH' => 'Artist', 'ANNO' => 'Comment'];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function read(string $path): ExtractedMetadata
    {
        $metadata = new ExtractedMetadata();
        $tags = [];
        $common = null;
        $pcm = false;
        try {
            foreach (IffChunks::read($path, 'FORM', ['AIFF', 'AIFC'], ['COMM' => 1024, 'NAME' => 1048576, 'AUTH' => 1048576, 'ANNO' => 1048576, 'ID3 ' => 16777216]) as $chunk) {
                $data = $chunk['data'];
                if ($chunk['id'] === 'COMM' && strlen($data) >= ($chunk['form'] === 'AIFC' ? 22 : 18)) {
                    $common = unpack('nchannels/Nframes/nbits', $data);
                    $common['sampleRate'] = $this->sampleRate(substr($data, 8, 10));
                    $pcm = $chunk['form'] === 'AIFF' || in_array(substr($data, 18, 4), ['NONE', 'twos', 'sowt'], true);
                } elseif ($chunk['id'] === 'ID3 ' && $data !== '') {
                    $metadata = (new Id3Reader($this->logger))->readBytes($data);
                } elseif (isset(self::TEXT_TAGS[$chunk['id']])) {
                    $field = self::TEXT_TAGS[$chunk['id']];
                    $tags[$field] = $data;
                }
            }
        } catch (\Throwable $error) {
            $this->logger->warning('Failed to parse AIFF file', ['path' => $path, 'error' => $error->getMessage()]);
        }
        ContainerTags::fill($metadata, $tags);
        $metadata->setDuration(null);
        if ($common !== null) {
            $rate = $common['sampleRate'];
            $metadata->setSampleRate($rate);
            $metadata->setChannels($common['channels'] > 0 ? $common['channels'] : null);
            if ($rate !== null && $common['frames'] > 0) {
                $metadata->setDuration($common['frames'] / $rate);
            }
            if ($pcm && $rate !== null && $common['channels'] > 0 && $common['bits'] > 0) {
                $metadata->setBitrate($rate * $common['channels'] * (int) ceil($common['bits'] / 8) * 8);
            }
        }
        return $metadata;
    }

    /** IEEE 754 extended precision: explicit integer bit and a 16383 exponent bias. */
    private function sampleRate(string $bytes): ?int
    {
        $parts = unpack('nexponent/Nhigh/Nlow', $bytes);
        $exponent = $parts['exponent'];
        if (($exponent & 0x8000) !== 0 || $exponent === 0 || $exponent === 0x7fff) {
            return null;
        }
        $rate = ($parts['high'] * 4294967296.0 + $parts['low']) * (2.0 ** ($exponent - 16383 - 63));
        return is_finite($rate) && $rate >= 1 && $rate < PHP_INT_MAX ? (int) round($rate) : null;
    }
}
