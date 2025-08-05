<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Sfx;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * FlatnessDB


Inputs:

  [vector_real] array - the input array


Outputs:

  [real] flatnessDB - the flatness dB


Description:

  This algorithm computes the flatness of an array, which is defined as the
  ratio between the geometric mean and the arithmetic mean converted to dB
  scale.
  
  Specifically, it can be used to compute spectral flatness [1,2], which is a
  measure of how noise-like a sound is, as opposed to being tone-like. The
  meaning of tonal in this context is in the sense of the amount of peaks or
  resonant structure in a power spectrum, as opposed to flat spectrum of a
  white noise. A high spectral flatness (approaching 1.0 for white noise)
  indicates that the spectrum has a similar amount of power in all spectral
  bands — this would sound similar to white noise, and the graph of the
  spectrum would appear relatively flat and smooth. A low spectral flatness
  (approaching 0.0 for a pure tone) indicates that the spectral power is
  concentrated in a relatively small number of bands — this would typically
  sound like a mixture of sine waves, and the spectrum would appear "spiky"
  
  The size of the input array must be greater than 0. If the input array is
  empty an exception will be thrown. This algorithm uses the Flatness algorithm
  and thus inherits its input requirements and exceptions.
  
  References:
    [1] G. Peeters, "A large set of audio features for sound description
    (similarity and classification) in the CUIDADO project," CUIDADO I.S.T.
    Project Report, 2004
  
    [2] Spectral flatness -  Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Spectral_flatness
 * 
 * Category: Sfx
 * Mode: standard
 */
class FlatnessDB extends BaseAlgorithm
{
    protected string $algorithmName = 'FlatnessDB';
    protected string $mode = 'standard';
    protected string $category = 'Sfx';

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
                "Failed to compute FlatnessDB: " . $e->getMessage(),
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