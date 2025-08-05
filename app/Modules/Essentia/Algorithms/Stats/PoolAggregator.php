<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Stats;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * PoolAggregator


Inputs:

  [pool] input - the input pool


Outputs:

  [pool] output - a pool containing the aggregate values of the input pool


Parameters:

  defaultStats:
    vector_string (default = ["mean", "stdev", "min", "max", "median"])
    the default statistics to be computed for each descriptor in the input pool

  exceptions:
    map_vector_string (default = {})
    a mapping between descriptor names (no duplicates) and the types of
    statistics to be computed for those descriptors (e.g. { lowlevel.bpm :
    [min, max], lowlevel.gain : [var, min, dmean] })


Description:

  This algorithm performs statistical aggregation on a Pool and places the
  results of the aggregation into a new Pool. Supported statistical units are:
    - 'min' (minimum),
    - 'max' (maximum),
    - 'median',
    - 'mean',
    - 'var' (variance),
    - 'stdev' (standard deviation),
    - 'skew' (skewness),
    - 'kurt' (kurtosis),
    - 'dmean' (mean of the derivative),
    - 'dvar' (variance of the derivative),
    - 'dmean2' (mean of the second derivative),
    - 'dvar2' (variance of the second derivative),
    - 'cov' (covariance), and
    - 'icov' (inverse covariance).
    - 'value' (copy of descriptor, but the value is placed under the name
  '<descriptor name>.value')
    - 'copy' (verbatim copy of descriptor, no aggregation; exclusive: cannot be
  performed with any other statistical units).
    - 'last' (last value of descriptor placed under the name '<descriptor
  name>'; exclusive: cannot be performed with any other statistical units
  
  These statistics can be computed for single-dimensional vectors (vectors of
  Reals) and two-dimensional vectors (vectors of vectors of Reals) in the Pool.
  Statistics for two-dimensional vectors are computed by aggregating each
  column placing the result into a vector of the same size as the size of each
  vector in the input Pool under the given descriptor (which implies their
  equal size).
  
  In the case of 'cov' and 'icov', two-dimensional vectors are required, and
  each statistic returns a square matrix with the dimensions equal to the
  length of the vectors under the given descriptor. Computing 'icov' requires
  the corresponding covariance matrix to be invertible.
  
  Note that only the absolute values of the first and second derivatives are
  considered when computing their mean ('dmean' and 'dmean2') and variance
  ('dvar' and 'dvar2'). This is to avoid a trivial solution for the mean.
  
  For vectors, if the input pool value consists of only one vector, its
  aggregation will be skipped, and the vector itself will be added to the
  output.
  
  The 'value' and 'copy' are auxiliary aggregation methods that can be used to
  copy values in the input Pool to the output Pool without aggregation. In the
  case of 'last', the last value in the input vector of Reals (or input vector
  of vectors of Reals) will be taken and saved as a single Real (or single
  vector of Reals) in the output Pool.
 * 
 * Category: Stats
 * Mode: standard
 */
class PoolAggregator extends BaseAlgorithm
{
    protected string $algorithmName = 'PoolAggregator';
    protected string $mode = 'standard';
    protected string $category = 'Stats';

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
                "Failed to compute PoolAggregator: " . $e->getMessage(),
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