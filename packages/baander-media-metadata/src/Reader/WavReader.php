<?php

declare(strict_types=1);

namespace Baander\MediaMetadata\Reader;

use Baander\MediaMetadata\Model\ExtractedMetadata;
use Psr\Log\LoggerInterface;

final class WavReader
{
    private const array INFO_TAGS = [
        'INAM' => 'Title', 'IART' => 'Artist', 'IPRD' => 'Album', 'ITRK' => 'TrackNumber', 'IPRT' => 'TrackNumber',
        'IGNR' => 'Genre', 'ICRD' => 'Year', 'ICMT' => 'Comment', 'IMUS' => 'Composer', 'IBPM' => 'Bpm',
    ];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function read(string $path): ExtractedMetadata
    {
        $metadata = new ExtractedMetadata();
        $info = [];
        $format = null;
        $audioSize = 0;
        $pcm = false;
        try {
            foreach (IffChunks::read($path, 'RIFF', ['WAVE'], ['fmt ' => 65536, 'LIST' => 1048576, 'id3 ' => 16777216, 'ID3 ' => 16777216]) as $chunk) {
                $data = $chunk['data'];
                if ($chunk['id'] === 'fmt ' && strlen($data) >= 16) {
                    $format = unpack('vcodec/vchannels/VsampleRate/VbyteRate/vblockAlign/vbits', $data);
                    $pcm = in_array($format['codec'], [1, 3], true);
                    if ($format['codec'] === 65534 && strlen($data) >= 40) {
                        $guid = bin2hex(substr($data, 24, 16));
                        $pcm = in_array($guid, ['0100000000001000800000aa00389b71', '0300000000001000800000aa00389b71'], true);
                    }
                } elseif ($chunk['id'] === 'data') {
                    $audioSize += $chunk['size'];
                } elseif (in_array($chunk['id'], ['id3 ', 'ID3 '], true) && $data !== '') {
                    $metadata = (new Id3Reader($this->logger))->readBytes($data);
                } elseif ($chunk['id'] === 'LIST' && substr($data, 0, 4) === 'INFO') {
                    $info = [...$info, ...$this->parseInfo($data)];
                }
            }
        } catch (\Throwable $error) {
            $this->logger->warning('Failed to parse WAV file', ['path' => $path, 'error' => $error->getMessage()]);
        }
        ContainerTags::fill($metadata, $info);
        // Tag duration is not evidence of the container's audio length.
        $metadata->setDuration(null);
        if ($format !== null) {
            $metadata->setSampleRate($format['sampleRate'] > 0 ? $format['sampleRate'] : null);
            $metadata->setChannels($format['channels'] > 0 ? $format['channels'] : null);
            $metadata->setBitrate($format['byteRate'] > 0 ? $format['byteRate'] * 8 : null);
            if ($pcm && $format['byteRate'] > 0 && $audioSize > 0) {
                $metadata->setDuration($audioSize / $format['byteRate']);
            }
        }
        return $metadata;
    }

    /** @return array<string, string> */
    private function parseInfo(string $data): array
    {
        $tags = [];
        for ($offset = 4; $offset + 8 <= strlen($data);) {
            $id = substr($data, $offset, 4);
            $size = unpack('V', substr($data, $offset + 4, 4))[1];
            $offset += 8;
            if ($size > strlen($data) - $offset) {
                break;
            }
            if (isset(self::INFO_TAGS[$id])) {
                $tags[self::INFO_TAGS[$id]] = substr($data, $offset, $size);
            }
            $offset += $size + ($size % 2);
        }
        return $tags;
    }
}
