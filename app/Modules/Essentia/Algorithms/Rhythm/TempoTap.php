<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Rhythm;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * TempoTap


Inputs:

  [vector_real] featuresFrame - input temporal features of a frame


Outputs:

  [vector_real] periods - list of tempo estimates found for each input feature, in frames
  [vector_real] phases - list of initial phase candidates found for each input feature, in frames


Parameters:

  frameHop:
    integer ∈ (0,inf) (default = 1024)
    number of feature frames separating two evaluations

  frameSize:
    integer ∈ (0,inf) (default = 256)
    number of audio samples in a frame

  maxTempo:
    integer ∈ [60,250] (default = 208)
    fastest tempo allowed to be detected [bpm]

  minTempo:
    integer ∈ [40,180] (default = 40)
    slowest tempo allowed to be detected [bpm]

  numberFrames:
    integer ∈ (0,inf) (default = 1024)
    number of feature frames to buffer on

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]

  tempoHints:
    vector_real (default = [])
    optional list of initial beat locations, to favor the detection of
    pre-determined tempo period and beats alignment [s]


Description:

  This algorithm estimates the periods and phases of a periodic signal,
  represented by a sequence of values of any number of detection functions,
  such as energy bands, onsets locations, etc. It requires to be sequentially
  run on a vector of such values ("featuresFrame") for each particular audio
  frame in order to get estimations related to that frames. The estimations are
  done for each detection function separately, utilizing the latest "frameHop"
  frames, including the present one, to compute autocorrelation. Empty
  estimations will be returned until enough frames are accumulated in the
  algorithm's buffer.
  The algorithm uses elements of the following beat-tracking methods:
   - BeatIt, elaborated by Fabien Gouyon and Simon Dixon (input features) [1]
   - Multi-comb filter with Rayleigh weighting, Mathew Davies [2]
  
  Parameter "maxTempo" should be 20bpm larger than "minTempo", otherwise an
  exception is thrown. The same applies for parameter "frameHop", which should
  not be greater than numberFrames. If the supplied "tempoHints" did not match
  any realistic bpm value, an exeception is thrown.
  
  This algorithm is thought to provide the input for TempoTapTicks algorithm.
  The "featureFrame" vectors can be formed by Multiplexer algorithm in the case
  of combining different features.
  
  Quality: outdated (use TempoTapDegara instead)
  
  References:
    [1] F. Gouyon, "A computational approach to rhythm description: Audio
    features for the computation of rhythm periodicity functions and their use
    in tempo induction and music content processing," UPF, Barcelona, Spain,
    2005.
  
    [2] M. Davies and M. Plumbley, "Causal tempo tracking of audio," in
    International Symposium on Music Information Retrieval (ISMIR'04), 2004.
 * 
 * Category: Rhythm
 * Mode: standard
 */
class TempoTap extends BaseAlgorithm
{
    protected string $algorithmName = 'TempoTap';
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
                "Failed to compute TempoTap: " . $e->getMessage(),
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