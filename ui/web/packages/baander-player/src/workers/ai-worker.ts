/**
 * @module workers/ai-worker
 * @description Web Worker for AI inference (scene classification, highlight detection).
 *
 * Runs TensorFlow.js inference off the main thread. Loads a MobileNet or
 * custom graph model on init, then classifies incoming ImageBitmap frames
 * and posts scene classifications and highlights back to the main thread.
 *
 * Messages (Main → Worker):
 *   { type: 'init', config: AIWorkerConfig }
 *   { type: 'classify-frame', frameData: ImageBitmap, timestamp: number }
 *   { type: 'terminate' }
 *
 * Messages (Worker → Main):
 *   { type: 'ready' }
 *   { type: 'error', payload: string }
 *   { type: 'classification', payload: SceneClassification }
 *   { type: 'highlight', payload: Highlight }
 *
 * TensorFlow.js model expectations:
 *   - Input: float32 tensor [1, 224, 224, 3], normalized to [0, 1]
 *   - Output: logits or probabilities of shape [1, numClasses]
 *
 * The worker maps model output indices to content categories via a label map.
 * If the model URL points to a MobileNet-style model, the ImageNet label
 * mapping produces reasonable content hints (e.g., "basketball" → sport).
 * For a custom scene classifier, provide a label_map.json at the model URL
 * directory that maps indices to content hint strings.
 *
 * @see ../ai/AIOrchestrator.ts — the main-thread counterpart
 * @see ../types.ts — SceneClassification, Highlight, ContentHint types
 */

/// <reference lib="webworker" />

declare module '@tensorflow/tfjs' {
  export const tensor: any;
  export const loadGraphModel: any;
  export const loadLayersModel: any;
  export const setBackend: any;
  export const ready: any;
  export const dispose: any;
  export const disposeVariables: any;
}
declare module '@tensorflow/tfjs-core' {
  export const tensor: any;
  export const loadGraphModel: any;
  export const loadLayersModel: any;
  export const setBackend: any;
  export const ready: any;
  export const dispose: any;
  export const disposeVariables: any;
}
declare module '@tensorflow/tfjs-backend-webgl' {}
declare module '@tensorflow/tfjs-backend-cpu' {}

// ---------------------------------------------------------------------------
// Types (mirrored from ../types.ts to avoid import issues in workers)
// ---------------------------------------------------------------------------

type ContentHint = 'static' | 'motion' | 'sport' | 'gaming' | 'animation' | 'unknown';

interface SceneClassification {
  timestamp: number;
  labels: string[];
  confidence: number;
  contentHint: ContentHint;
}

interface SceneHighlight {
  startTime: number;
  endTime: number;
  confidence: number;
  label: string;
}

interface AIWorkerConfig {
  sampleIntervalSec: number;
  minConfidence: number;
  maxHighlights: number;
  useWebNN: boolean;
  modelUrl: string;
}

// ---------------------------------------------------------------------------
// ImageNet → ContentHint mapping
// ---------------------------------------------------------------------------

/**
 * Maps ImageNet class names (or substrings thereof) to ContentHint categories.
 * MobileNet produces 1000 ImageNet labels. We collapse them into the 6
 * content categories the ABR controller understands.
 *
 * For custom models: place a `label_map.json` alongside the model that maps
 * index → ContentHint directly, and this table is bypassed.
 */
const IMAGENET_TO_CONTENT_HINT: ReadonlyMap<string, ContentHint> = new Map([
  // Sport — ball sports, fields, athletes
  ['basketball', 'sport'],
  ['soccer', 'sport'],
  ['football', 'sport'],
  ['baseball', 'sport'],
  ['tennis', 'sport'],
  ['volleyball', 'sport'],
  ['golf', 'sport'],
  ['hockey', 'sport'],
  ['racket', 'sport'],
  ['stadium', 'sport'],
  ['sport', 'sport'],
  ['ball', 'sport'],
  ['goal', 'sport'],

  // Gaming — screens, controllers, pixel art
  ['screen', 'gaming'],
  ['monitor', 'gaming'],
  ['television', 'gaming'],
  ['desktop', 'gaming'],
  ['laptop', 'gaming'],
  ['joystick', 'gaming'],
  ['gamepad', 'gaming'],
  ['arcade', 'gaming'],

  // Animation — cartoon, comic, illustrated
  ['comic', 'animation'],
  ['cartoon', 'animation'],
  ['animation', 'animation'],
  ['illustration', 'animation'],
  ['graphic', 'animation'],

  // Motion — vehicles, fast-moving objects, water
  ['car', 'motion'],
  ['race', 'motion'],
  ['speed', 'motion'],
  ['motor', 'motion'],
  ['train', 'motion'],
  ['airplane', 'motion'],
  ['rocket', 'motion'],
  ['ship', 'motion'],
  ['boat', 'motion'],
  ['ocean', 'motion'],
  ['wave', 'motion'],
  ['water', 'motion'],
  ['traffic', 'motion'],

  // Everything else defaults to 'static' below
]);

/** Thresholds for highlight detection. */
const HIGHLIGHT_WINDOW_SIZE = 3;
const HIGHLIGHT_MIN_CONFIDENCE = 0.75;
const HIGHLIGHT_TIME_SPAN_SEC = 4;

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

let config: AIWorkerConfig | null = null;
let model: any = null; // tf.GraphModel | tf.LayersModel — typed as any to avoid TF import at top-level
let tf: any = null;    // @tensorflow/tfjs module reference
let labelMap: Map<number, string> | null = null; // Custom label map (index → label)
let initialized = false;

// Highlight tracking — rolling window of recent classifications
interface ClassificationRecord {
  timestamp: number;
  contentHint: ContentHint;
  confidence: number;
  label: string;
}

let recentClassifications: ClassificationRecord[] = [];
let highlightsEmitted = 0;
let lastHighlightEndTime = -Infinity;

// ---------------------------------------------------------------------------
// TF.js Loader
// ---------------------------------------------------------------------------

/**
 * Attempt to load @tensorflow/tfjs in the worker context.
 * Returns null if TF.js is not available.
 */
async function loadTfjs(): Promise<any> {
  // Try standard import — works if tfjs is bundled or available via importmap
  try {
    const tfModule = await import('@tensorflow/tfjs');
    return tfModule;
  } catch {
    // Continue to fallbacks
  }

  // Try with backend-specific imports
  try {
    const tfModule = await import('@tensorflow/tfjs-core');
    await import('@tensorflow/tfjs-backend-webgl');
    return tfModule;
  } catch {
    // Continue to fallbacks
  }

  // Try CPU-only backend as last resort
  try {
    const tfModule = await import('@tensorflow/tfjs-core');
    await import('@tensorflow/tfjs-backend-cpu');
    return tfModule;
  } catch {
    return null;
  }
}

/**
 * Load the classification model.
 * Supports TF.js GraphModel (SavedModel / tfhub format) and LayersModel (Keras).
 */
async function loadModel(modelUrl: string, tfInstance: any): Promise<any> {
  // Ensure model URL ends with / for TF.js convention
  const url = modelUrl.endsWith('/') ? modelUrl : `${modelUrl}/`;

  try {
    // Try GraphModel first (most common for converted models)
    return await tfInstance.loadGraphModel(url);
  } catch {
    // Fallback to LayersModel (Keras JSON format)
    try {
      return await tfInstance.loadLayersModel(`${url}model.json`);
    } catch {
      throw new Error(
        `Failed to load model from ${url}. ` +
        `Ensure model.json or saved_model.pb exists at the URL.`,
      );
    }
  }
}

/**
 * Load a custom label map if available.
 * Expects a JSON file at {modelUrl}/label_map.json or {modelUrl}labels.json
 * Format: { "0": "static", "1": "motion", ... } or ["static", "motion", ...]
 */
async function loadLabelMap(modelUrl: string): Promise<Map<number, string> | null> {
  const baseUrl = modelUrl.endsWith('/') ? modelUrl : `${modelUrl}/`;

  for (const name of ['label_map.json', 'labels.json']) {
    try {
      const response = await fetch(`${baseUrl}${name}`);
      if (!response.ok) continue;

      const data = await response.json();

      // Object format: { "0": "static", "1": "motion", ... }
      if (typeof data === 'object' && !Array.isArray(data)) {
        const map = new Map<number, string>();
        for (const [key, value] of Object.entries(data)) {
          map.set(parseInt(key, 10), String(value));
        }
        return map;
      }

      // Array format: ["static", "motion", ...]
      if (Array.isArray(data)) {
        const map = new Map<number, string>();
        for (let i = 0; i < data.length; i++) {
          map.set(i, String(data[i]));
        }
        return map;
      }
    } catch {
      continue;
    }
  }

  return null;
}

// ---------------------------------------------------------------------------
// Inference
// ---------------------------------------------------------------------------

/**
 * Convert an ImageBitmap to a TF.js tensor suitable for model input.
 * Resizes to 224×224 and normalizes pixel values to [0, 1].
 */
function bitmapToTensor(bitmap: ImageBitmap, tfInstance: any): any {
  // Create an OffscreenCanvas to read pixel data from the ImageBitmap
  const canvas = new OffscreenCanvas(224, 224);
  const ctx = canvas.getContext('2d')!;

  // Draw scaled to 224×224
  ctx.drawImage(bitmap, 0, 0, 224, 224);

  // Get raw pixel data
  const imageData = ctx.getImageData(0, 0, 224, 224);
  const pixelData = imageData.data; // Uint8ClampedArray [r, g, b, a, r, g, b, a, ...]

  // Create float32 tensor [224, 224, 3] normalized to [0, 1]
  const float32Data = new Float32Array(224 * 224 * 3);
  const pixelCount = 224 * 224;

  for (let i = 0; i < pixelCount; i++) {
    const srcOffset = i * 4; // RGBA
    float32Data[i * 3] = pixelData[srcOffset]! / 255;         // R
    float32Data[i * 3 + 1] = pixelData[srcOffset + 1]! / 255; // G
    float32Data[i * 3 + 2] = pixelData[srcOffset + 2]! / 255; // B
  }

  // Shape into [1, 224, 224, 3] batch tensor
  return tfInstance.tensor(float32Data, [1, 224, 224, 3], 'float32');
}

/**
 * Run model prediction on the input tensor.
 * Returns the output tensor (probabilities or logits).
 */
function predict(inputTensor: any): any {
  if (!model) throw new Error('Model not loaded');

  // Execute model
  const output = model.predict(inputTensor);

  // Some models return an array of outputs
  if (Array.isArray(output)) {
    return output[0];
  }

  return output;
}

/**
 * Post-process model output into a SceneClassification.
 * Extracts top-K predictions, maps to content hints.
 */
function postProcess(
  outputTensor: any,
  timestamp: number,
  tfInstance: any,
): SceneClassification {
  // Get the probability array from the output tensor
  const probs = outputTensor.dataSync() as Float32Array;
  const numClasses = probs.length;

  // Build indexed entries for sorting
  const entries: Array<{ index: number; prob: number }> = [];
  for (let i = 0; i < numClasses; i++) {
    entries.push({ index: i, prob: probs[i]! });
  }

  // Sort descending by probability
  entries.sort((a, b) => b.prob - a.prob);

  // Take top 5 labels
  const topK = 5;
  const topLabels: string[] = [];
  let topContentHint: ContentHint = 'unknown';
  let topConfidence = 0;
  let topLabel = '';

  for (let k = 0; k < Math.min(topK, entries.length); k++) {
    const entry = entries[k]!;

    // Resolve label
    const label = resolveLabel(entry.index);
    topLabels.push(label);

    if (k === 0) {
      topConfidence = entry.prob;
      topLabel = label;
      topContentHint = resolveContentHint(label);
    }
  }

  // Clean up tensors
  tfInstance.dispose(outputTensor);

  return {
    timestamp,
    labels: topLabels,
    confidence: topConfidence,
    contentHint: topContentHint,
  };
}

/**
 * Resolve a class index to a human-readable label.
 */
function resolveLabel(classIndex: number): string {
  // Custom label map takes priority
  if (labelMap) {
    const custom = labelMap.get(classIndex);
    if (custom) return custom;
  }

  // Default: use index as string
  return `class_${classIndex}`;
}

/**
 * Resolve a label string to a ContentHint category.
 */
function resolveContentHint(label: string): ContentHint {
  const lower = label.toLowerCase();

  // Check direct mapping
  if (lower === 'static' || lower === 'still' || lower === 'calm') return 'static';
  if (lower === 'motion' || lower === 'moving' || lower === 'action') return 'motion';
  if (lower === 'sport' || lower === 'sports' || lower === 'athletic') return 'sport';
  if (lower === 'gaming' || lower === 'game' || lower === 'video game') return 'gaming';
  if (lower === 'animation' || lower === 'animated' || lower === 'cartoon') return 'animation';

  // Check ImageNet substring mapping
  for (const [substring, hint] of IMAGENET_TO_CONTENT_HINT) {
    if (lower.includes(substring)) return hint;
  }

  // Default: static (most video content is relatively static)
  return 'static';
}

// ---------------------------------------------------------------------------
// Highlight Detection
// ---------------------------------------------------------------------------

/**
 * Track a classification and detect highlights.
 * A highlight fires when the same content hint has sustained high confidence
 * over a rolling window of HIGHLIGHT_WINDOW_SIZE consecutive classifications.
 */
function detectHighlights(classification: SceneClassification): void {
  if (!config) return;

  const record: ClassificationRecord = {
    timestamp: classification.timestamp,
    contentHint: classification.contentHint,
    confidence: classification.confidence,
    label: classification.labels[0] ?? 'unknown',
  };

  recentClassifications.push(record);

  // Trim to window size
  while (recentClassifications.length > HIGHLIGHT_WINDOW_SIZE) {
    recentClassifications.shift();
  }

  // Need a full window to evaluate
  if (recentClassifications.length < HIGHLIGHT_WINDOW_SIZE) return;

  // Check if all recent classifications have the same content hint and high confidence
  const firstHint = recentClassifications[0]!.contentHint;
  let allSameHint = true;
  let avgConfidence = 0;

  for (const rec of recentClassifications) {
    if (rec.contentHint !== firstHint) {
      allSameHint = false;
      break;
    }
    avgConfidence += rec.confidence;
  }

  if (!allSameHint) return;

  avgConfidence /= recentClassifications.length;

  // Only fire if average confidence is high enough
  if (avgConfidence < HIGHLIGHT_MIN_CONFIDENCE) return;

  // Compute highlight time range
  const startTime = recentClassifications[0]!.timestamp - config.sampleIntervalSec;
  const endTime = recentClassifications[recentClassifications.length - 1]!.timestamp + config.sampleIntervalSec;

  // Don't overlap with the last emitted highlight
  if (startTime < lastHighlightEndTime) return;

  // Check highlight count limit
  if (highlightsEmitted >= config.maxHighlights) return;

  // Only fire highlights for non-static content
  if (firstHint === 'static' || firstHint === 'unknown') return;

  lastHighlightEndTime = endTime;
  highlightsEmitted++;

  const highlight: SceneHighlight = {
    startTime: Math.max(0, startTime),
    endTime,
    confidence: avgConfidence,
    label: firstHint,
  };

  self.postMessage({
    type: 'highlight',
    payload: highlight,
  });
}

// ---------------------------------------------------------------------------
// No-model fallback: visual analysis heuristic
// ---------------------------------------------------------------------------

/**
 * When TF.js is not available, perform a simple visual analysis on the
 * ImageBitmap to produce a basic content hint. This uses color variance
 * and edge detection heuristics — not ML, but better than random.
 */
function classifyByHeuristics(bitmap: ImageBitmap, timestamp: number): SceneClassification {
  const width = 64; // Downsample for speed
  const height = 64;
  const canvas = new OffscreenCanvas(width, height);
  const ctx = canvas.getContext('2d')!;

  ctx.drawImage(bitmap, 0, 0, width, height);
  const imageData = ctx.getImageData(0, 0, width, height);
  const pixels = imageData.data;

  // Compute color statistics
  let totalR = 0, totalG = 0, totalB = 0;
  let varianceR = 0, varianceG = 0, varianceB = 0;
  let edgeCount = 0;
  const pixelCount = width * height;

  // Mean color
  for (let i = 0; i < pixelCount; i++) {
    const offset = i * 4;
    totalR += pixels[offset]!;
    totalG += pixels[offset + 1]!;
    totalB += pixels[offset + 2]!;
  }

  const meanR = totalR / pixelCount;
  const meanG = totalG / pixelCount;
  const meanB = totalB / pixelCount;

  // Variance and simple edge detection
  for (let i = 0; i < pixelCount; i++) {
    const offset = i * 4;
    varianceR += (pixels[offset]! - meanR) ** 2;
    varianceG += (pixels[offset + 1]! - meanG) ** 2;
    varianceB += (pixels[offset + 2]! - meanB) ** 2;

    // Horizontal edge (Sobel-like)
    if (i % width < width - 1) {
      const nextOffset = (i + 1) * 4;
      const diff =
        Math.abs(pixels[offset]! - pixels[nextOffset]!) +
        Math.abs(pixels[offset + 1]! - pixels[nextOffset + 1]!) +
        Math.abs(pixels[offset + 2]! - pixels[nextOffset + 2]!);
      if (diff > 100) edgeCount++;
    }
  }

  const colorVariance = (varianceR + varianceG + varianceB) / (3 * pixelCount);
  const edgeDensity = edgeCount / pixelCount;

  // Heuristic classification
  let contentHint: ContentHint;
  let confidence: number;
  let label: string;

  const greenDominance = meanG > meanR * 1.2 && meanG > meanB * 1.2;
  const blueDominance = meanB > meanR * 1.3 && meanB > meanG * 1.2;

  if (colorVariance < 500 && edgeDensity < 0.05) {
    contentHint = 'static';
    confidence = 0.7;
    label = 'static';
  } else if (edgeDensity > 0.25 && colorVariance > 3000) {
    contentHint = 'gaming';
    confidence = 0.55;
    label = 'high-detail';
  } else if (greenDominance && edgeDensity > 0.1) {
    contentHint = 'sport';
    confidence = 0.5;
    label = 'field';
  } else if (blueDominance && colorVariance > 1500) {
    contentHint = 'motion';
    confidence = 0.5;
    label = 'sky-water';
  } else if (colorVariance > 2000 && edgeDensity > 0.15) {
    contentHint = 'motion';
    confidence = 0.55;
    label = 'complex-scene';
  } else {
    contentHint = 'static';
    confidence = 0.6;
    label = 'low-activity';
  }

  return {
    timestamp,
    labels: [label],
    confidence,
    contentHint,
  };
}

// ---------------------------------------------------------------------------
// Message Handler
// ---------------------------------------------------------------------------

self.onmessage = async (event: MessageEvent) => {
  const { type } = event.data;

  switch (type) {
    // -------------------------------------------------------------------
    // INIT: Load TF.js + model + label map
    // -------------------------------------------------------------------
    case 'init': {
      config = event.data.config as AIWorkerConfig;

      try {
        // Step 1: Load TensorFlow.js
        tf = await loadTfjs();

        if (tf) {
          // Register appropriate backend
          if (config.useWebNN) {
            try {
              await tf.setBackend('webnn');
            } catch {
              // WebNN not available, fall back to WebGL
            }
          }

          try {
            await tf.setBackend('webgl');
          } catch {
            try {
              await tf.setBackend('cpu');
            } catch {
              throw new Error('No TF.js backend available');
            }
          }

          await tf.ready();

          // Step 2: Load model
          model = await loadModel(config.modelUrl, tf);

          // Step 3: Load custom label map if available
          labelMap = await loadLabelMap(config.modelUrl);

          initialized = true;

          self.postMessage({ type: 'ready' });
        } else {
          // TF.js not available — operate in heuristic-only mode
          initialized = true;
          self.postMessage({ type: 'ready' });
        }
      } catch (err) {
        self.postMessage({
          type: 'error',
          payload: `AI worker init failed: ${err instanceof Error ? err.message : String(err)}`,
        });

        // Still mark as initialized so heuristic fallback works
        initialized = true;
      }

      break;
    }

    // -------------------------------------------------------------------
    // CLASSIFY FRAME: Run inference on an ImageBitmap
    // -------------------------------------------------------------------
    case 'classify-frame': {
      if (!config) return;

      const { frameData, timestamp } = event.data as {
        frameData: ImageBitmap;
        timestamp: number;
      };

      try {
        let classification: SceneClassification;

        if (tf && model) {
          // Full ML pipeline: ImageBitmap → Tensor → Predict → Post-process
          const inputTensor = bitmapToTensor(frameData, tf);

          try {
            const outputTensor = predict(inputTensor);
            classification = postProcess(outputTensor, timestamp, tf);
          } finally {
            tf.dispose(inputTensor);
          }
        } else {
          // Heuristic fallback when TF.js or model is unavailable
          classification = classifyByHeuristics(frameData, timestamp);
        }

        // Only emit if confidence meets threshold
        if (classification.confidence >= config.minConfidence) {
          self.postMessage({
            type: 'classification',
            payload: classification,
          });

          // Run highlight detection
          detectHighlights(classification);
        }
      } catch (err) {
        self.postMessage({
          type: 'error',
          payload: `Classification failed at t=${timestamp.toFixed(2)}s: ${
            err instanceof Error ? err.message : String(err)
          }`,
        });
      } finally {
        frameData.close();
      }

      break;
    }

    // -------------------------------------------------------------------
    // TERMINATE: Dispose model and close worker
    // -------------------------------------------------------------------
    case 'terminate': {
      try {
        if (model && tf) {
          model.dispose();
        }
        if (tf) {
          tf.disposeVariables();
        }
      } catch {
        // Best-effort cleanup
      }

      model = null;
      tf = null;
      labelMap = null;
      config = null;
      recentClassifications = [];

      self.close();
      break;
    }
  }
};
