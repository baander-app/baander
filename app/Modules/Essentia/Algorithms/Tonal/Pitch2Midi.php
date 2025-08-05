<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Tonal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * Pitch2Midi


Inputs:

     [real] pitch - pitch given in Hz for conversion
  [integer] voiced - whether the frame is voiced or not, (0, 1)


Outputs:

  [vector_string] messageType - the output of MIDI message type, as string, {noteoff, noteon, noteoff-noteon}
    [vector_real] midiNoteNumber - the output of detected MIDI note number, as integer, in range [0,127]
    [vector_real] timeCompensation - time to be compensated in the messages


Parameters:

  applyTimeCompensation:
    bool ∈ {true,false} (default = true)
    whether to apply time compensation in the timestamp of the note toggle
    messages.

  hopSize:
    integer ∈ [1,inf) (default = 128)
    Pitch Detection analysis hop size in samples, equivalent to I/O buffer size

  midiBufferDuration:
    real ∈ [0.005,0.5] (default = 0.0149999996647)
    duration in seconds of buffer used for voting in the note toggle detection
    algorithm

  minFrequency:
    real ∈ [20,20000] (default = 60)
    minimum detectable frequency

  minNoteChangePeriod:
    real ∈ (0,1] (default = 0.0299999993294)
    minimum time to wait until a note change is detected (s)

  minOccurrenceRate:
    real ∈ [0,1] (default = 0.5)
    minimum number of times a midi note has to ocur compared to total capacity

  minOffsetCheckPeriod:
    real ∈ (0,1] (default = 0.20000000298)
    minimum time to wait until an offset is detected (s)

  minOnsetCheckPeriod:
    real ∈ (0,1] (default = 0.0750000029802)
    minimum time to wait until an onset is detected (s)

  sampleRate:
    integer ∈ [8000,inf) (default = 44100)
    Audio sample rate

  transpositionAmount:
    integer ∈ (-69,50) (default = 0)
    Apply transposition (in semitones) to the detected MIDI notes.

  tuningFrequency:
    integer ∈ {432,440} (default = 440)
    reference tuning frequency in Hz


Description:

  This algorithm estimates the midi note ON/OFF detection from raw pitch and
  voiced values, using midi buffer and uncertainty checkers.
 * 
 * Category: Tonal
 * Mode: standard
 */
class Pitch2Midi extends BaseAlgorithm
{
    protected string $algorithmName = 'Pitch2Midi';
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
                "Failed to compute Pitch2Midi: " . $e->getMessage(),
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