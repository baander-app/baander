<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Rhythm;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * BeatsLoudness


Inputs:

  [vector_real] signal - the input audio signal


Outputs:

         [vector_real] loudness - the beat's energy in the whole spectrum
  [vector_vector_real] loudnessBandRatio - the ratio of the beat's energy on each frequency band


Parameters:

  beatDuration:
    real ∈ (0,inf) (default = 0.0500000007451)
    the duration of the window in which the beat will be restricted [s]

  beatWindowDuration:
    real ∈ (0,inf) (default = 0.10000000149)
    the duration of the window in which to look for the beginning of the beat
    (centered around the positions in 'beats') [s]

  beats:
    vector_real (default = [])
    the list of beat positions (each position is in seconds)

  frequencyBands:
    vector_real (default = [20, 150, 400, 3200, 7000, 22000])
    the list of bands to compute energy ratios [Hz

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the audio sampling rate [Hz]


Description:

  This algorithm computes the spectrum energy of beats in an audio signal given
  their positions. The energy is computed both on the whole frequency range and
  for each of the specified frequency bands. See the SingleBeatLoudness
  algorithm for a more detailed explanation.
  
  Note that the algorithm will output empty results in the case if no beats are
  specified in the "beats" parameter.
 * 
 * Category: Rhythm
 * Mode: standard
 */
class BeatsLoudness extends BaseAlgorithm
{
    protected string $algorithmName = 'BeatsLoudness';
    protected string $mode = 'standard';
    protected string $category = 'Rhythm';

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
                "Failed to compute BeatsLoudness: " . $e->getMessage(),
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