import { decodeBlurhash } from '@/app/libs/blurhash/decode.ts';

export const calculateLuminance = (r: number, g: number, b: number): number => {
  // Convert sRGB to linear RGB
  const [R, G, B] = [r, g, b].map(v => {
    v /= 255;
    return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
  });

  // Calculate luminance
  return 0.2126 * R + 0.7152 * G + 0.0722 * B;
};

export const getAverageColorFromBlurhash = (pixels: Uint8ClampedArray): { r: number, g: number, b: number } => {
  let r = 0, g = 0, b = 0;

  for (let i = 0; i < pixels.length; i += 4) {
    r += pixels[i];
    g += pixels[i + 1];
    b += pixels[i + 2];
  }

  const pixelCount = pixels.length / 4;
  r = Math.floor(r / pixelCount);
  g = Math.floor(g / pixelCount);
  b = Math.floor(b / pixelCount);

  return { r, g, b };
};

function getIsLight(r: number, g: number, b: number) {
  const luminance = calculateLuminance(r, g, b);

  // Assuming 0.5 as the threshold for light/dark background
  return luminance > 0.5;
}

export const getIsLightFromBlurhash = (blurhash: string, width: number, height: number): boolean => {
  const { r, g, b } = getAverageColorFromBlurhash(decodeBlurhash(blurhash, width, height));

  return getIsLight(r, g, b);
};

export const getIsLightFromPixels = (pixels: Uint8ClampedArray): boolean => {
  const { r, g, b } = getAverageColorFromBlurhash(pixels);

  return getIsLight(r, g, b);
};