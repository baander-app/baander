<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Tonal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * ChordsDescriptors


Inputs:

  [vector_string] chords - the chord progression
         [string] key - the key of the whole song, from A to G
         [string] scale - the scale of the whole song (major or minor)


Outputs:

  [vector_real] chordsHistogram - the normalized histogram of chords
         [real] chordsNumberRate - the ratio of different chords from the total number of chords in the progression
         [real] chordsChangesRate - the rate at which chords change in the progression
       [string] chordsKey - the most frequent chord of the progression
       [string] chordsScale - the scale of the most frequent chord of the progression (either 'major' or 'minor')


Description:

  Given a chord progression this algorithm describes it by means of key, scale,
  histogram, and rate of change.
  Note:
    - chordsHistogram indexes follow the circle of fifths order, while being
  shifted to the input key and scale
    - key and scale are taken from the most frequent chord. In the case where
  multiple chords are equally frequent, the chord is hierarchically chosen from
  the circle of fifths.
    - chords should follow this name convention `<A-G>[<#/b><m>]` (i.e. C, C#
  or C#m are valid chords). Chord names not fitting this convention will throw
  an exception.
  
  Input chords vector may not be empty, otherwise an exception is thrown.
  
  References:
    [1] Chord progression - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Chord_progression
  
    [2] Circle of fifths - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Circle_of_fifths
 * 
 * Category: Tonal
 * Mode: standard
 */
class ChordsDescriptors extends BaseAlgorithm
{
    protected string $algorithmName = 'ChordsDescriptors';
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
                "Failed to compute ChordsDescriptors: " . $e->getMessage(),
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