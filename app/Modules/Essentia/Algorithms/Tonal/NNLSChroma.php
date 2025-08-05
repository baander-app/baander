<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Tonal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * NNLSChroma


Inputs:

  [vector_vector_real] logSpectrogram - log spectrum frames
         [vector_real] meanTuning - mean tuning frames
         [vector_real] localTuning - local tuning frames


Outputs:

  [vector_vector_real] tunedLogfreqSpectrum - Log frequency spectrum after tuning
  [vector_vector_real] semitoneSpectrum - a spectral representation with one bin per semitone
  [vector_vector_real] bassChromagram -  a 12-dimensional chromagram, restricted to the bass range
  [vector_vector_real] chromagram - a 12-dimensional chromagram, restricted with mid-range emphasis


Parameters:

  chromaNormalization:
    string ∈ {none,maximum,L1,L2} (default = "none")
    determines whether or how the chromagrams are normalised

  frameSize:
    integer ∈ (1,inf) (default = 1025)
    the input frame size of the spectrum vector

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the input sample rate

  spectralShape:
    real ∈ (0.5,0.9) (default = 0.699999988079)
     the shape of the notes in the NNLS dictionary

  spectralWhitening:
    real ∈ [0,1.0] (default = 1)
    determines how much the log-frequency spectrum is whitened

  tuningMode:
    string ∈ {global,local} (default = "global")
    local uses a local average for tuning, global uses all audio frames. Local
    tuning is only advisable when the tuning is likely to change over the audio

  useNNLS:
    bool ∈ {true,false} (default = true)
    toggle between NNLS approximate transcription and linear spectral mapping


Description:

  This algorithm extracts treble and bass chromagrams from a sequence of
  log-frequency spectrum frames.
  On this representation, two processing steps are performed:
    -tuning, after which each centre bin (i.e. bin 2, 5, 8, ...) corresponds to
  a semitone, even if the tuning of the piece deviates from 440 Hz standard
  pitch.
    -running standardisation: subtraction of the running mean, division by the
  running standard deviation. This has a spectral whitening effect.
  This code is ported from NNLS Chroma [1, 2]. To achieve similar results
  follow this processing chain:
  frame slicing with sample rate = 44100, frame size = 16384, hop size = 2048
  -> Windowing with Hann and no normalization -> Spectrum -> LogSpectrum.
  
  References:
    [1] Mauch, M., & Dixon, S. (2010, August). Approximate Note Transcription
    for the Improved Identification of Difficult Chords. In ISMIR (pp.
  135-140).
    [2] Chordino and NNLS Chroma,
    http://www.isophonics.net/nnls-chroma
 * 
 * Category: Tonal
 * Mode: standard
 */
class NNLSChroma extends BaseAlgorithm
{
    protected string $algorithmName = 'NNLSChroma';
    protected string $mode = 'standard';
    protected string $category = 'Tonal';

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
                "Failed to compute NNLSChroma: " . $e->getMessage(),
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