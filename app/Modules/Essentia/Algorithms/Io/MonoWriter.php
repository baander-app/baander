<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Io;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * MonoWriter


Inputs:

  [vector_real] audio - the audio signal


Parameters:

  bitrate:
    integer ∈ {32,40,48,56,64,80,96,112,128,144,160,192,224,256,320} (default = 192)
    the audio bit rate for compressed formats [kbps]

  filename:
    string
    the name of the encoded file

  format:
    string ∈ {wav,aiff,mp3,ogg,flac} (default = "wav")
    the audio output format

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the audio sampling rate [Hz]


Description:

  This algorithm writes a mono audio stream to a file.
  
  The algorithm uses FFmpeg. Supported formats are wav, aiff, mp3, flac and
  ogg. An exception is thrown when other extensions are given. The default
  FFmpeg encoders are used for each format. Note that to encode in mp3 format
  it is mandatory that FFmpeg was configured with mp3 enabled.
  
  If the file specified by filename could not be opened or the header of the
  file omits channel's information, an exception is thrown.
 * 
 * Category: Io
 * Mode: standard
 */
class MonoWriter extends BaseAlgorithm
{
    protected string $algorithmName = 'MonoWriter';
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
                "Failed to compute MonoWriter: " . $e->getMessage(),
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