<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Tonal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * Key


Inputs:

  [vector_real] pcp - the input pitch class profile


Outputs:

  [string] key - the estimated key, from A to G
  [string] scale - the scale of the key (major or minor)
    [real] strength - the strength of the estimated key
    [real] firstToSecondRelativeStrength - the relative strength difference between the best estimate and second best estimate of the key


Parameters:

  numHarmonics:
    integer ∈ [1,inf) (default = 4)
    number of harmonics that should contribute to the polyphonic profile (1
    only considers the fundamental harmonic)

  pcpSize:
    integer ∈ [12,inf) (default = 36)
    number of array elements used to represent a semitone times 12 (this
    parameter is only a hint, during computation, the size of the input PCP is
    used instead)

  profileType:
    string ∈ {diatonic,krumhansl,temperley,weichai,tonictriad,temperley2005,thpcp,shaath,gomez,noland,edmm,edma,bgate,braw} (default = "bgate")
    the type of polyphic profile to use for correlation calculation

  slope:
    real ∈ [0,inf) (default = 0.600000023842)
    value of the slope of the exponential harmonic contribution to the
    polyphonic profile

  useMajMin:
    bool ∈ {true,false} (default = false)
    use a third profile called 'majmin' for ambiguous tracks [4]. Only avalable
    for the edma, bgate and braw profiles

  usePolyphony:
    bool ∈ {true,false} (default = true)
    enables the use of polyphonic profiles to define key profiles (this
    includes the contributions from triads as well as pitch harmonics)

  useThreeChords:
    bool ∈ {true,false} (default = true)
    consider only the 3 main triad chords of the key (T, D, SD) to build the
    polyphonic profiles


Description:

  This algorithm computes key estimate given a pitch class profile (HPCP). The
  algorithm was severely adapted and changed from the original implementation
  for readability and speed.
  
  Key will throw exceptions either when the input pcp size is not a positive
  multiple of 12 or if the key could not be found. Also if parameter "scale" is
  set to "minor" and the profile type is set to "weichai"
  
    Abouth the Key Profiles:
    - 'Diatonic' - binary profile with diatonic notes of both modes. Could be
  useful for ambient music or diatonic music which is not strictly 'tonal
  functional'.
    - 'Tonic Triad' - just the notes of the major and minor chords. Exclusively
  for testing.
    - 'Krumhansl' - reference key profiles after cognitive experiments with
  users. They should work generally fine for pop music.
    - 'Temperley' - key profiles extracted from corpus analysis of
  euroclassical music. Therefore, they perform best on this repertoire
  (especially in minor).
    - 'Shaath' -  profiles based on Krumhansl's specifically tuned to popular
  and electronic music.
    - 'Noland' - profiles from Bach's 'Well Tempered Klavier'.
    - 'edma' - automatic profiles extracted from corpus analysis of electronic
  dance music [3]. They normally perform better that Shaath's
    - 'edmm' - automatic profiles extracted from corpus analysis of electronic
  dance music and manually tweaked according to heuristic observation. It will
  report major modes (which are poorly represented in EDM) as minor, but
  improve performance otherwise [3].
    - 'braw' - profiles obtained by calculating the median profile for each
  mode from a subset of BeatPort dataset. There is an extra profile obtained
  from ambiguous tracks that are reported as minor [4]
    - 'bgate' - same as braw but zeroing the 4 less relevant elements of each
  profile [4]
  
  The standard mode of the algorithm estimates key/scale for a given HPCP
  vector.
  
  The streaming mode first accumulates a stream of HPCP vectors and computes
  its mean to provide the estimation. In this mode, the algorithm can apply a
  tuning correction, based on peaks in the bins of the accumulated HPCP
  distribution [3] (the `averageDetuningCorrection` parameter). This detuning
  approach requires a high resolution of the input HPCP vectors (`pcpSize`
  larger than 12).
  
  References:
    [1] E. Gómez, "Tonal Description of Polyphonic Audio for Music Content
    Processing," INFORMS Journal on Computing, vol. 18, no. 3, pp. 294–304,
    2006.
  
    [2] D. Temperley, "What's key for key? The Krumhansl-Schmuckler
    key-finding algorithm reconsidered", Music Perception vol. 17, no. 1,
    pp. 65-100, 1999.
  
    [3] Á. Faraldo, E. Gómez, S. Jordà, P.Herrera, "Key Estimation in
  Electronic
    Dance Music. Proceedings of the 38th International Conference on
  information
    Retrieval, pp. 335-347, 2016.
  
    [4] Faraldo, Á., Jordà, S., & Herrera, P. (2017, June). A multi-profile
  method
    for key estimation in edm. In Audio Engineering Society Conference: 2017
  AES
    International Conference on Semantic Audio. Audio Engineering Society.
 * 
 * Category: Tonal
 * Mode: standard
 */
class Key extends BaseAlgorithm
{
    protected string $algorithmName = 'Key';
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
                "Failed to compute Key: " . $e->getMessage(),
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