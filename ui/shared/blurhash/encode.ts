import { encode83 } from './base83';
import { encodeAc, encodeDc, srgbToLinear } from './srgb';
import { multiplyBasisFunction } from './dct';
import type { EncodeOptions } from './types';

const DEFAULT_COMPONENT_X = 4;
const DEFAULT_COMPONENT_Y = 3;
const DEFAULT_MAX_WIDTH = 64;
const DEFAULT_MAX_HEIGHT = 64;

/**
 * Raw pixel data input for cross-platform encoding.
 */
export interface PixelData {
  /**
   * Width of the image in pixels.
   */
  width: number;

  /**
   * Height of the image in pixels.
   */
  height: number;

  /**
   * Pixel data in RGBA format (0-255 per channel).
   * Can be Uint8ClampedArray, Uint8Array, or number[].
   */
  data: Uint8ClampedArray | Uint8Array | ArrayLike<number>;
}

/**
 * Encode an image to a BlurHash string.
 *
 * Accepts various input types for cross-platform compatibility:
 * - ImageBitmap, HTMLImageElement, HTMLCanvasElement, ImageData (Web/Electron)
 * - PixelData object with raw RGBA bytes (all platforms)
 *
 * For React Native, extract pixel data from an image and pass as PixelData.
 *
 * @param source - Image source or raw pixel data
 * @param options - Encoding options
 * @returns BlurHash string
 */
export async function encode(
  source: ImageBitmap | HTMLImageElement | HTMLCanvasElement | ImageData | PixelData,
  options: EncodeOptions = {},
): Promise<string> {
  const {
    componentsX = DEFAULT_COMPONENT_X,
    componentsY = DEFAULT_COMPONENT_Y,
    maxWidth = DEFAULT_MAX_WIDTH,
    maxHeight = DEFAULT_MAX_HEIGHT,
  } = options;

  if (componentsX < 1 || componentsX > 9) {
    throw new Error('componentsX must be between 1 and 9');
  }
  if (componentsY < 1 || componentsY > 9) {
    throw new Error('componentsY must be between 1 and 9');
  }

  // Normalize to PixelData
  let pixelData: PixelData;

  if (isPixelData(source)) {
    pixelData = source;
  } else if (source instanceof ImageData) {
    pixelData = { width: source.width, height: source.height, data: source.data };
  } else {
    // Web/Electron: extract pixels from bitmap/canvas/image
    const bitmap = source instanceof ImageBitmap
      ? source
      : await createImageBitmap(source);

    const scale = Math.min(1, Math.min(maxWidth / bitmap.width, maxHeight / bitmap.height));
    const width = Math.floor(bitmap.width * scale);
    const height = Math.floor(bitmap.height * scale);

    const canvas = new OffscreenCanvas(width, height);
    const ctx = canvas.getContext('2d')!;
    ctx.drawImage(bitmap, 0, 0, width, height);
    const imageData = ctx.getImageData(0, 0, width, height);

    pixelData = { width, height, data: imageData.data };

    if (source instanceof ImageBitmap) {
      bitmap.close();
    }
  }

  return encodePixelData(pixelData, { componentsX, componentsY });
}

/**
 * Encode raw pixel data to a BlurHash string.
 * Core encoding logic, platform-agnostic.
 *
 * @param pixelData - Raw RGBA pixel data
 * @param options - Encoding options
 * @returns BlurHash string
 */
export function encodePixelData(
  pixelData: PixelData,
  options: Pick<EncodeOptions, 'componentsX' | 'componentsY'> = {},
): string {
  const { componentsX = DEFAULT_COMPONENT_X, componentsY = DEFAULT_COMPONENT_Y } = options;

  const { width, height, data } = pixelData;
  const pixels = new Float32Array(width * height * 3);

  // Convert to linear RGB
  for (let i = 0; i < width * height; i++) {
    const r = srgbToLinear(data[i * 4] / 255);
    const g = srgbToLinear(data[i * 4 + 1] / 255);
    const b = srgbToLinear(data[i * 4 + 2] / 255);
    pixels[i * 3] = r;
    pixels[i * 3 + 1] = g;
    pixels[i * 3 + 2] = b;
  }

  // Encode size flag (4 bits)
  const sizeFlag = (componentsX - 1) + (componentsY - 1) * 9;

  // Calculate DC component (average color)
  let dcR = 0, dcG = 0, dcB = 0;
  for (let i = 0; i < width * height; i++) {
    dcR += pixels[i * 3];
    dcG += pixels[i * 3 + 1];
    dcB += pixels[i * 3 + 2];
  }
  const numPixels = width * height;
  dcR = encodeDc(dcR / numPixels, dcG / numPixels, dcB / numPixels);

  // Calculate AC components
  const acCount = componentsX * componentsY - 1;
  const acValues = new Float32Array(acCount * 3);

  let acIndex = 0;
  for (let y = 0; y < componentsY; y++) {
    for (let x = 0; x < componentsX; x++) {
      if (x === 0 && y === 0) continue;

      const rChannel = new Float32Array(width * height);
      const gChannel = new Float32Array(width * height);
      const bChannel = new Float32Array(width * height);

      for (let i = 0; i < width * height; i++) {
        rChannel[i] = pixels[i * 3];
        gChannel[i] = pixels[i * 3 + 1];
        bChannel[i] = pixels[i * 3 + 2];
      }

      const r = multiplyBasisFunction(rChannel, width, height, x, y);
      const g = multiplyBasisFunction(gChannel, width, height, x, y);
      const b = multiplyBasisFunction(bChannel, width, height, x, y);

      acValues[acIndex * 3] = r;
      acValues[acIndex * 3 + 1] = g;
      acValues[acIndex * 3 + 2] = b;

      acIndex++;
    }
  }

  // Find maximum AC value for quantization
  let maxAcValue = 0;
  for (let i = 0; i < acValues.length; i++) {
    maxAcValue = Math.max(maxAcValue, Math.abs(acValues[i]));
  }

  // Quantize max value (6 bits, giving max value range of 0-63)
  const quantizedMax = Math.floor(maxAcValue * 166 - 0.5);
  const maxAc = Math.max(0, Math.min(63, quantizedMax));
  const actualMax = (maxAc + 1) / 166;

  // Build BlurHash string
  let hash = encode83(sizeFlag, 1);
  hash += encode83(maxAc, 1);
  hash += encode83(dcR, 4);

  for (let i = 0; i < acCount; i++) {
    const r = acValues[i * 3];
    const g = acValues[i * 3 + 1];
    const b = acValues[i * 3 + 2];

    hash += encode83(encodeAc(r, g, b, actualMax), 2);
  }

  return hash;
}

/**
 * Extract component counts from a BlurHash string.
 */
export function extractComponents(blurhash: string): [number, number] {
  const CHAR_MAP = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz#$%*+,-.:;=?@[]^_{|}~';
  const sizeFlag = decode83(blurhash.substring(0, 1), CHAR_MAP);
  const componentsX = (sizeFlag % 9) + 1;
  const componentsY = Math.floor(sizeFlag / 9) + 1;
  return [componentsX, componentsY];
}

function isPixelData(value: unknown): value is PixelData {
  return (
    typeof value === 'object' &&
    value !== null &&
    'width' in value &&
    'height' in value &&
    'data' in value
  );
}

function decode83(str: string, charMap = CHAR_MAP): number {
  const CHAR_MAP = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz#$%*+,-.:;=?@[]^_{|}~';
  let result = 0;

  for (let i = 0; i < str.length; i++) {
    const index = charMap.indexOf(str[i]);
    if (index === -1) {
      throw new Error(`Invalid base83 character: ${str[i]}`);
    }
    result = result * 83 + index;
  }

  return result;
}

const CHAR_MAP = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz#$%*+,-.:;=?@[]^_{|}~';
