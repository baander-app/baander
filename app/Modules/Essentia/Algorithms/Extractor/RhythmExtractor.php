<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Extractor;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * RhythmExtractor


Inputs:

  [vector_real] signal - the audio input signal


Outputs:

         [real] bpm - the tempo estimation [bpm]
  [vector_real] ticks -  the estimated tick locations [s]
  [vector_real] estimates - the bpm estimation per frame [bpm]
  [vector_real] bpmIntervals - list of beats interval [s]


Parameters:

  frameHop:
    integer ∈ (0,inf) (default = 1024)
    the number of feature frames separating two evaluations

  frameSize:
    integer ∈ (0,inf) (default = 1024)
    the number audio samples used to compute a feature

  hopSize:
    integer ∈ (0,inf) (default = 256)
    the number of audio samples per features

  lastBeatInterval:
    real ∈ [0,inf) (default = 0.10000000149)
    the minimum interval between last beat and end of file [s]

  maxTempo:
    integer ∈ [60,250] (default = 208)
    the fastest tempo to detect [bpm]

  minTempo:
    integer ∈ [40,180] (default = 40)
    the slowest tempo to detect [bpm]

  numberFrames:
    integer ∈ (0,inf) (default = 1024)
    the number of feature frames to buffer on

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]

  tempoHints:
    vector_real (default = [])
    the optional list of initial beat locations, to favor the detection of
    pre-determined tempo period and beats alignment [s]

  tolerance:
    real ∈ [0,inf) (default = 0.239999994636)
    the minimum interval between two consecutive beats [s]

  useBands:
    bool ∈ {true,false} (default = true)
    whether or not to use band energy as periodicity function

  useOnset:
    bool ∈ {true,false} (default = true)
    whether or not to use onsets as periodicity function


Description:

  This algorithm estimates the tempo in bpm and beat positions given an audio
  signal. The algorithm combines several periodicity functions and estimates
  beats using TempoTap and TempoTapTicks. It combines:
  - onset detection functions based on high-frequency content (see
  OnsetDetection)
  - complex-domain spectral difference function (see OnsetDetection)
  - periodicity function based on energy bands (see FrequencyBands,
  TempoScaleBands)
  
  Note that this algorithm is outdated in terms of beat tracking accuracy, and
  it is highly recommended to use RhythmExtractor2013 instead.
  
  Quality: outdated (use RhythmExtractor2013 instead).
  
  An exception is thrown if neither "useOnset" nor "useBands" are enabled (i.e.
  set to true).
 * 
 * Category: Extractor
 * Mode: standard
 */
class RhythmExtractor extends BaseAlgorithm
{
    protected string $algorithmName = 'RhythmExtractor';
    protected string $mode = 'standard';
    protected string $category = 'Extractor';

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
                "Failed to compute RhythmExtractor: " . $e->getMessage(),
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