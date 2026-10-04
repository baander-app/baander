// The optional ML dependency is loaded dynamically. Declare only the concrete
// tensor/model operations this worker consumes, without importing a runtime.
interface InferenceTensor {
  dataSync(): Float32Array | Int32Array | Uint8Array;
  dispose(): void;
}
interface InferenceModel {
  predict(input: InferenceTensor): InferenceTensor | InferenceTensor[] | Record<string, InferenceTensor>;
  dispose(): void;
}
interface TensorFlowRuntime {
  tensor(values: Float32Array, shape: number[], dtype: 'float32'): InferenceTensor;
  setBackend(backend: string): Promise<boolean>;
  ready(): Promise<void>;
  dispose(tensor: InferenceTensor): void;
  disposeVariables(): void;
  loadGraphModel?: (url: string) => Promise<InferenceModel>;
  loadLayersModel?: (url: string) => Promise<InferenceModel>;
}

declare module '@tensorflow/tfjs' {
  export function tensor(values: Float32Array, shape: number[], dtype: 'float32'): InferenceTensor;
  export function loadGraphModel(url: string): Promise<InferenceModel>;
  export function loadLayersModel(url: string): Promise<InferenceModel>;
  export function setBackend(backend: string): Promise<boolean>;
  export function ready(): Promise<void>;
  export function dispose(tensor: InferenceTensor): void;
  export function disposeVariables(): void;
}
declare module '@tensorflow/tfjs-core' {
  export { tensor, setBackend, ready, dispose, disposeVariables } from '@tensorflow/tfjs';
}
declare module '@tensorflow/tfjs-backend-webgl' {}
declare module '@tensorflow/tfjs-backend-cpu' {}
