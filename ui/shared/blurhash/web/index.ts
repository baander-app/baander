/**
 * Web/Electron-specific BlurHash rendering utilities.
 *
 * These functions use Canvas and ImageBitmap APIs which are available
 * in web browsers and Electron, but not in React Native.
 *
 * Import from '@baander/blurhash/web' when using in web or Electron.
 */

import { decodeToArray } from '../decode';
import type { DecodeOptions } from '../types';

/**
 * Clear a canvas and fill it with a solid color.
 * Useful for cleanup before re-rendering.
 */
export function clearCanvas(canvas: HTMLCanvasElement | OffscreenCanvas): void {
  const ctx = canvas.getContext('2d');
  if (!ctx) return;
  ctx.clearRect(0, 0, canvas.width, canvas.height);
}

/**
 * Draw a BlurHash to a canvas element.
 *
 * @param blurhash - The BlurHash string
 * @param canvas - Target canvas element
 * @param punch - Optional punch value to increase contrast (default: 1)
 */
export function drawBlurhash(
  blurhash: string,
  canvas: HTMLCanvasElement | OffscreenCanvas,
  punch = 1,
): void {
  const ctx = canvas.getContext('2d');
  if (!ctx) {
    throw new Error('Failed to get 2D context from canvas');
  }

  const { width, height } = canvas;
  const pixels = decodeToArray(blurhash, { width, height, punch });

  const imageData = new ImageData(new Uint8ClampedArray(pixels.buffer), width, height);
  ctx.putImageData(imageData, 0, 0);
}

/**
 * Create an ImageBitmap from a BlurHash string.
 *
 * @param blurhash - The BlurHash string
 * @param options - Decode options with width, height, and optional punch value
 * @returns Promise<ImageBitmap>
 */
export async function createImageBitmapFromBlurhash(
  blurhash: string,
  options: DecodeOptions,
): Promise<ImageBitmap> {
  const { width, height, punch = 1 } = options;
  const pixels = decodeToArray(blurhash, { width, height, punch });

  const imageData = new ImageData(new Uint8ClampedArray(pixels.buffer), width, height);
  const canvas = new OffscreenCanvas(width, height);
  const ctx = canvas.getContext('2d')!;
  ctx.putImageData(imageData, 0, 0);

  return createImageBitmap(canvas);
}

/**
 * Get a data URL for a BlurHash.
 *
 * @param blurhash - The BlurHash string
 * @param options - Decode options with width, height, and optional punch value
 * @returns Promise<string> - Data URL (data:image/png;base64,...)
 */
export async function toDataURL(
  blurhash: string,
  options: DecodeOptions,
): Promise<string> {
  const { width, height, punch = 1 } = options;
  const pixels = decodeToArray(blurhash, { width, height, punch });

  const imageData = new ImageData(new Uint8ClampedArray(pixels.buffer), width, height);
  const canvas = new OffscreenCanvas(width, height);
  const ctx = canvas.getContext('2d')!;
  ctx.putImageData(imageData, 0, 0);

  const blob = await canvas.convertToBlob();
  const reader = new FileReader();
  return new Promise((resolve, reject) => {
    reader.onload = () => resolve(reader.result as string);
    reader.onerror = reject;
    reader.readAsDataURL(blob);
  });
}

/**
 * Get a Blob for a BlurHash.
 *
 * @param blurhash - The BlurHash string
 * @param options - Decode options with width, height, and optional punch value
 * @returns Promise<Blob>
 */
export async function toBlob(
  blurhash: string,
  options: DecodeOptions,
): Promise<Blob> {
  const { width, height, punch = 1 } = options;
  const pixels = decodeToArray(blurhash, { width, height, punch });

  const imageData = new ImageData(new Uint8ClampedArray(pixels.buffer), width, height);
  const canvas = new OffscreenCanvas(width, height);
  const ctx = canvas.getContext('2d')!;
  ctx.putImageData(imageData, 0, 0);

  return canvas.convertToBlob();
}
