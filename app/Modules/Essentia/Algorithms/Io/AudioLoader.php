<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Io;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * AudioLoader


Outputs:

  [vector_stereosample] audio - the input audio signal
                 [real] sampleRate - the sampling rate of the audio signal [Hz]
              [integer] numberChannels - the number of channels
               [string] md5 - the MD5 checksum of raw undecoded audio payload
              [integer] bit_rate - the bit rate of the input audio, as reported by the decoder codec
               [string] codec - the codec that is used to decode the input audio


Parameters:

  audioStream:
    integer ∈ [0,inf) (default = 0)
    audio stream index to be loaded. Other streams are no taken into account
    (e.g. if stream 0 is video and 1 is audio use index 0 to access it.)

  computeMD5:
    bool ∈ {true,false} (default = false)
    compute the MD5 checksum

  filename:
    string
    the name of the file from which to read


Description:

  This algorithm loads the single audio stream contained in a given audio or
  video file. Supported formats are all those supported by the FFmpeg library
  including wav, aiff, flac, ogg and mp3.
  
  This algorithm will throw an exception if it was not properly configured
  which is normally due to not specifying a valid filename. Invalid names
  comprise those with extensions different than the supported  formats and non
  existent files. If using this algorithm on Windows, you must ensure that the
  filename is encoded as UTF-8
  
  Note: ogg files are decoded in reverse phase, due to be using ffmpeg library.
  
  References:
    [1] WAV - Wikipedia, the free encyclopedia,
        http://en.wikipedia.org/wiki/Wav
    [2] Audio Interchange File Format - Wikipedia, the free encyclopedia,
        http://en.wikipedia.org/wiki/Aiff
    [3] Free Lossless Audio Codec - Wikipedia, the free encyclopedia,
        http://en.wikipedia.org/wiki/Flac
    [4] Vorbis - Wikipedia, the free encyclopedia,
        http://en.wikipedia.org/wiki/Vorbis
    [5] MP3 - Wikipedia, the free encyclopedia,
        http://en.wikipedia.org/wiki/Mp3
 * 
 * Category: Io
 * Mode: standard
 */
class AudioLoader extends BaseAlgorithm
{
    protected string $algorithmName = 'AudioLoader';
    protected string $mode = 'standard';
    protected string $category = 'Io';

    public function compute($input): array
    {
        try {
            // Input validation based on algorithm type
            $this->validateAlgorithmInput($input);
            
            // Convert input to appropriate format
            $processedInput = $this->prepareInput($input);
            
            // Execute the algorithm
            $result = $this->executeAlgorithm($processedInput);
            
            return $this->processOutput($result);
            
        } catch (\Exception $e) {
            throw new AlgorithmException(
                "Failed to compute AudioLoader: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function validateAlgorithmInput($input): void
    {
        // Category-specific input validation
        switch ($this->category) {
            case 'Spectral':
            case 'Temporal':
                $this->validateInput($input, 'array');
                break;
            case 'Io':
                if (!is_string($input) && !($input instanceof AudioVector)) {
                    throw new AlgorithmException('IO algorithms expect string path or AudioVector');
                }
                break;
            default:
                // Generic validation
                if (!is_array($input) && !($input instanceof AudioVector) && !is_numeric($input)) {
                    throw new AlgorithmException('Unsupported input type for algorithm');
                }
        }
    }

    private function prepareInput($input)
    {
        if ($input instanceof AudioVector) {
            return $input->toCArray($this->essentia->getFFI());
        }
        
        return $input;
    }

    private function executeAlgorithm($input)
    {
        // This would contain the actual FFI calls to Essentia
        // Implementation depends on the specific algorithm
        
        // Placeholder for algorithm execution
        return [];
    }

    private function processOutput($result): array
    {
        // Process and format the output from Essentia
        return is_array($result) ? $result : [$result];
    }
}