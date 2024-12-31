import { decodeBlurhash } from '@/libs/blurhash/decode.ts';
import { getIsLightFromPixels } from '@/libs/blurhash/luminance.ts';

/**
 * Util function to convert Uint8ClampedArray to base64 image string
 *
 * @param bytes
 * @param width
 * @param height
 */
const uint8ClampedArrayToBase64 = (bytes: Uint8ClampedArray, width: number, height: number): string => {
  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext('2d');
  if (!ctx) {
    throw new Error('Could not get canvas context');
  }
  const imageData = new ImageData(bytes, width, height);
  ctx.putImageData(imageData, 0, 0);
  return canvas.toDataURL();
};

/**
 * Generate a CSS background image string using a BlurHash and the actual image URL.
 */
export const generateBlurhashBackgroundImage = (
  blurHash: string,
  width: number,
  height: number,
) => {
  // Process blurhash to pixels
  const pixels = decodeBlurhash(blurHash, width, height);
  const isLight = getIsLightFromPixels(pixels);

  // Convert pixels to base64 image string
  const value = uint8ClampedArrayToBase64(pixels, width, height);

  return {
    backgroundUrl: `url(${value})`,
    isLight,
  };
};
