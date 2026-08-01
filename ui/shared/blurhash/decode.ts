import { decode83 } from './base83';
import { decodeAc, decodeDc, linearToByte } from './srgb';
import type { DecodeOptions } from './types';

/**
 * Decoded pixel data result.
 */
export interface DecodedBlurhash {
  /**
   * Width of the decoded image.
   */
  width: number;

  /**
   * Height of the decoded image.
   */
  height: number;

  /**
   * Pixel data in RGBA format (0-255 per channel).
   */
  data: Uint8ClampedArray;
}

/**
 * Decode a BlurHash string to raw pixel data.
 *
 * Returns platform-agnostic RGBA bytes that can be used with:
 * - Web/Electron: new ImageData(result.data, result.width, result.height)
 * - React Native: Pass to react-native-fast-image or similar
 *
 * @param blurhash - The BlurHash string
 * @param options - Decode options with width, height, and optional punch value
 * @returns Decoded pixel data
 */
export function decode(blurhash: string, options: DecodeOptions): DecodedBlurhash {
  const { width, height, punch = 1 } = options;
  const data = decodeToArray(blurhash, { width, height, punch });

  return { width, height, data: new Uint8ClampedArray(data.buffer) };
}

/**
 * Decode a BlurHash string to a Float32Array of pixel data (0-255 RGBA).
 *
 * The Float32Array is returned for performance, but values are in byte range (0-255).
 * Cast to Uint8ClampedArray or Uint8Array for use with ImageData or similar.
 *
 * @param blurhash - The BlurHash string
 * @param options - Decode options with width, height, and optional punch value
 * @returns Float32Array of pixel data in [r, g, b, a, r, g, b, a, ...] format
 */
export function decodeToArray(blurhash: string, options: DecodeOptions): Float32Array {
  const { width, height, punch = 1 } = options;

  if (!blurhash || blurhash.length < 6) {
    throw new Error('Invalid BlurHash string');
  }

  const sizeFlag = decode83(blurhash.substring(0, 1));
  const componentsX = (sizeFlag % 9) + 1;
  const componentsY = Math.floor(sizeFlag / 9) + 1;

  if (blurhash.length !== 4 + 2 * componentsX * componentsY) {
    throw new Error('Invalid BlurHash length');
  }

  const maxAc = decode83(blurhash.substring(1, 2));
  const dc = decode83(blurhash.substring(2, 6));

  const dcColor = decodeDc(dc);
  const acValues = new Float32Array((componentsX * componentsY - 1) * 3);

  let acIndex = 0;
  for (let i = 6; i < blurhash.length; i += 2) {
    const acValue = decode83(blurhash.substring(i, i + 2));
    const ac = decodeAc(acValue, (maxAc + 1) / 166);
    acValues[acIndex * 3] = ac[0] * punch;
    acValues[acIndex * 3 + 1] = ac[1] * punch;
    acValues[acIndex * 3 + 2] = ac[2] * punch;
    acIndex++;
  }

  const result = new Float32Array(width * height * 4);

  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      let r = dcColor[0];
      let g = dcColor[1];
      let b = dcColor[2];

      acIndex = 0;
      for (let cy = 0; cy < componentsY; cy++) {
        for (let cx = 0; cx < componentsX; cx++) {
          if (cx === 0 && cy === 0) continue;

          const basis = Math.cos(Math.PI * cx * x / width) * Math.cos(Math.PI * cy * y / height);
          r += acValues[acIndex * 3] * basis;
          g += acValues[acIndex * 3 + 1] * basis;
          b += acValues[acIndex * 3 + 2] * basis;

          acIndex++;
        }
      }

      const index = (y * width + x) * 4;
      result[index] = linearToByte(r);
      result[index + 1] = linearToByte(g);
      result[index + 2] = linearToByte(b);
      result[index + 3] = 255;
    }
  }

  return result;
}
