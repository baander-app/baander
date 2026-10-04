type PredictionOutput = InferenceTensor | InferenceTensor[] | Record<string, InferenceTensor>;

function isTensor(output: InferenceTensor | Record<string, InferenceTensor>): output is InferenceTensor {
  return typeof output.dataSync === 'function' && typeof output.dispose === 'function';
}

export function selectPredictionTensor(output: PredictionOutput, dispose: (tensor: InferenceTensor) => void): InferenceTensor {
  const tensors = Array.isArray(output) ? output : isTensor(output) ? [output] : Object.values(output);
  const first = tensors[0];
  if (!first) throw new Error('Model returned no output tensors');
  for (const tensor of new Set(tensors)) {
    if (tensor !== first) dispose(tensor);
  }
  return first;
}
