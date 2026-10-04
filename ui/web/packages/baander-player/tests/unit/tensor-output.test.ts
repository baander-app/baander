import { describe, expect, it, vi } from 'vitest';
import { selectPredictionTensor } from '../../src/workers/tensor-output';

function tensor() {
  return { dataSync: () => new Float32Array([0.8, 0.2]), dispose: vi.fn() };
}

describe('inference output ownership', () => {
  it('uses named GraphModel outputs and releases unused tensors exactly once', () => {
    const probabilities = tensor(), auxiliary = tensor();
    const dispose = vi.fn((output: ReturnType<typeof tensor>) => output.dispose());
    const output = selectPredictionTensor({ probabilities, auxiliary, duplicate: auxiliary }, dispose);
    expect(output).toBe(probabilities);
    expect(output.dataSync()[0]).toBeCloseTo(.8);
    expect(probabilities.dispose).not.toHaveBeenCalled();
    expect(auxiliary.dispose).toHaveBeenCalledOnce();
    expect(dispose).toHaveBeenCalledOnce();
  });

  it('releases unused array outputs without disposing the selected tensor or aliases', () => {
    const probabilities = tensor(), auxiliary = tensor();
    const dispose = vi.fn((output: ReturnType<typeof tensor>) => output.dispose());
    expect(selectPredictionTensor([probabilities, auxiliary, auxiliary, probabilities], dispose)).toBe(probabilities);
    expect(probabilities.dispose).not.toHaveBeenCalled();
    expect(auxiliary.dispose).toHaveBeenCalledOnce();
  });

  it('preserves single-output tensors and rejects empty predictions', () => {
    const probabilities = tensor(), dispose = vi.fn();
    expect(selectPredictionTensor(probabilities, dispose)).toBe(probabilities);
    expect(dispose).not.toHaveBeenCalled();
    expect(() => selectPredictionTensor([], dispose)).toThrow('Model returned no output tensors');
    expect(() => selectPredictionTensor({}, dispose)).toThrow('Model returned no output tensors');
  });
});
