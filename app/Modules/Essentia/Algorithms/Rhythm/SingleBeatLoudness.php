<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Rhythm;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * SingleBeatLoudness


Inputs:

  [vector_real] beat - audio segement containing a beat


Outputs:

         [real] loudness - the beat's energy across the whole spectrum
  [vector_real] loudnessBandRatio - the beat's energy ratio for each band


Parameters:

  beatDuration:
    real ∈ (0,inf) (default = 0.0500000007451)
    window size for the beat's energy computation (the window starts at the
    onset) [s]

  beatWindowDuration:
    real ∈ (0,inf) (default = 0.10000000149)
    window size for the beat's onset detection [s]

  frequencyBands:
    vector_real (default = [0, 200, 400, 800, 1600, 3200, 22000])
    frequency bands

  onsetStart:
    string ∈ {sumEnergy,peakEnergy} (default = "sumEnergy")
    criteria for finding the start of the beat

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the audio sampling rate [Hz]


Description:

  This algorithm computes the spectrum energy of a single beat across the whole
  frequency range and on each specified frequency band given an audio segment.
  It detects the onset of the beat within the input segment, computes spectrum
  on a window starting on this onset, and estimates energy (see Energy and
  EnergyBandRatio algorithms). The frequency bands used by default are: 0-200
  Hz, 200-400 Hz, 400-800 Hz, 800-1600 Hz, 1600-3200 Hz, 3200-22000Hz,
  following E. Scheirer [1].
  
  This algorithm throws an exception either when parameter beatDuration is
  larger than beatWindowSize or when the size of the input beat segment is less
  than beatWindowSize plus beatDuration.
  
  References:
    [1] E. D. Scheirer, "Tempo and beat analysis of acoustic musical signals,"
    The Journal of the Acoustical Society of America, vol. 103, p. 588, 1998.
 * 
 * Category: Rhythm
 * Mode: standard
 */
class SingleBeatLoudness extends BaseAlgorithm
{
    protected string $algorithmName = 'SingleBeatLoudness';
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
                "Failed to compute SingleBeatLoudness: " . $e->getMessage(),
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