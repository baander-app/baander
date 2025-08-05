<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Io;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * Audio2Midi


Inputs:

  [vector_real] frame - the input frame to analyse


Outputs:

           [real] pitch - pitch given in Hz
           [real] loudness - detected loudness in decibels
  [vector_string] messageType - the output of MIDI message type, as string, {noteoff, noteon, noteoff-noteon}
    [vector_real] midiNoteNumber - the output of detected MIDI note number, as integer, in range [0,127]
    [vector_real] timeCompensation - time to be compensated in the messages


Parameters:

  applyTimeCompensation:
    bool ∈ {true,false} (default = true)
    whether to apply time compensation correction to MIDI note detection

  hopSize:
    integer ∈ [1,inf) (default = 32)
    equivalent to I/O buffer size

  loudnessThreshold:
    real ∈ [-inf,0] (default = -51)
    loudness level above/below which note ON/OFF start to be considered, in
    decibels

  maxFrequency:
    real ∈ [10,20000] (default = 2300)
    maximum frequency to detect in Hz

  midiBufferDuration:
    real ∈ [0.005,0.5] (default = 0.0500000007451)
    duration in seconds of buffer used for voting in MidiPool algorithm

  minFrequency:
    real ∈ [10,20000] (default = 60)
    minimum frequency to detect in Hz

  minNoteChangePeriod:
    real ∈ (0,1] (default = 0.0299999993294)
    minimum time to wait until a note change is detected (testing only)

  minOccurrenceRate:
    real ∈ [0,1] (default = 0.5)
    rate of predominant pitch occurrence in MidiPool buffer to consider note ON
    event

  minOffsetCheckPeriod:
    real ∈ (0,1] (default = 0.20000000298)
    minimum time to wait until an offset is detected (testing only)

  minOnsetCheckPeriod:
    real ∈ (0,1] (default = 0.0750000029802)
    minimum time to wait until an onset is detected (testing only)

  pitchConfidenceThreshold:
    real ∈ [0,1] (default = 0.25)
    level of pitch confidence above which note ON/OFF start to be considered

  sampleRate:
    integer ∈ [8000,inf) (default = 44100)
    sample rate of incoming audio frames

  transpositionAmount:
    integer ∈ (-69,50) (default = 0)
    Apply transposition (in semitones) to the detected MIDI notes.

  tuningFrequency:
    integer ∈ {432,440} (default = 440)
    tuning frequency for semitone index calculation, corresponding to A3 [Hz]


Description:

  Wrapper around Audio2Pitch and Pitch2Midi for real time application. This
  algorithm has a state that is used to estimate note on/off events based on
  consequent compute() calls.
 * 
 * Category: Io
 * Mode: standard
 */
class Audio2Midi extends BaseAlgorithm
{
    protected string $algorithmName = 'Audio2Midi';
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
                "Failed to compute Audio2Midi: " . $e->getMessage(),
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