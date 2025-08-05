<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Io;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * Audio2Pitch


Inputs:

  [vector_real] frame - the input frame to analyse


Outputs:

     [real] pitch - detected pitch in Hz
     [real] pitchConfidence - confidence of detected pitch (from 0.0 to 1.0)
     [real] loudness - detected loudness in decibels
  [integer] voiced - voiced frame categorization, 1 for voiced and 0 for unvoiced frame


Parameters:

  frameSize:
    integer ∈ [1,inf) (default = 1024)
    size of input frame in samples

  loudnessThreshold:
    real ∈ [-inf,0] (default = -51)
    loudness level above/below which note ON/OFF start to be considered, in
    decibels

  maxFrequency:
    real ∈ [10,20000] (default = 2300)
    maximum frequency to detect in Hz

  minFrequency:
    real ∈ [10,20000] (default = 60)
    minimum frequency to detect in Hz

  pitchAlgorithm:
    string ∈ {pitchyin,pitchyinfft} (default = "pitchyinfft")
    pitch algorithm to use

  pitchConfidenceThreshold:
    real ∈ [0,1] (default = 0.25)
    level of pitch confidence above/below which note ON/OFF start to be
    considered

  sampleRate:
    integer ∈ [8000,inf) (default = 44100)
    sample rate of incoming audio frames

  tolerance:
    real ∈ [0,1] (default = 1)
    sets tolerance for peak detection on pitch algorithm

  weighting:
    string ∈ {custom,A,B,C,D,Z} (default = "custom")
    string to assign a weighting function


Description:

  This algorithm computes pitch with various pitch algorithms, specifically
  targeted for real-time pitch detection on audio signals. The algorithm
  internally uses pitch estimation with PitchYin (pitchyin) and PitchYinFFT
  (pitchyinfft).
 * 
 * Category: Io
 * Mode: standard
 */
class Audio2Pitch extends BaseAlgorithm
{
    protected string $algorithmName = 'Audio2Pitch';
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
                "Failed to compute Audio2Pitch: " . $e->getMessage(),
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