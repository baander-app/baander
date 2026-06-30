/**
 * Convert sRGB to linear RGB.
 */
export function srgbToLinear(value: number): number {
  const sign = Math.sign(value);
  const abs = Math.abs(value);

  if (abs <= 0.04045) {
    return sign * (abs / 12.92);
  }

  return sign * (((abs + 0.055) / 1.055) ** 2.4);
}

/**
 * Convert linear RGB to sRGB.
 */
export function linearToSrgb(value: number): number {
  const sign = Math.sign(value);
  const abs = Math.abs(value);

  if (abs > 0.0031308) {
    return sign * (1.055 * (abs ** (1 / 2.4)) - 0.055);
  }

  return sign * (12.92 * abs);
}

/**
 * Convert a linear RGB value to a byte (0-255).
 */
export function linearToByte(value: number): number {
  const srgb = linearToSrgb(value);
  return Math.max(0, Math.min(255, Math.round(srgb * 255)));
}

/**
 * Convert a byte (0-255) to linear RGB.
 */
export function byteToLinear(byte: number): number {
  return srgbToLinear(byte / 255);
}

/**
 * Convert DC component (average color) to linear RGB.
 */
export function decodeDc(dc: number): [number, number, number] {
  const r = byteToLinear(dc >> 16);
  const g = byteToLinear((dc >> 8) & 0xff);
  const b = byteToLinear(dc & 0xff);
  return [r, g, b];
}

/**
 * Encode DC component (average color) from linear RGB.
 */
export function encodeDc(r: number, g: number, b: number): number {
  const rByte = linearToByte(r);
  const gByte = linearToByte(g);
  const bByte = linearToByte(b);
  return (rByte << 16) + (gByte << 8) + bByte;
}

/**
 * Decode AC component (chroma data) to linear RGB.
 */
export function decodeAc(ac: number, maximumValue: number): [number, number, number] {
  const quantR = Math.floor(ac / (19 * 19));
  const quantG = Math.floor(ac / 19) % 19;
  const quantB = ac % 19;

  const r = signPow((quantR - 9) / 9, 2.0) * maximumValue;
  const g = signPow((quantG - 9) / 9, 2.0) * maximumValue;
  const b = signPow((quantB - 9) / 9, 2.0) * maximumValue;

  return [r, g, b];
}

/**
 * Encode AC component (chroma data) from linear RGB.
 */
export function encodeAc(r: number, g: number, b: number, maximumValue: number): number {
  const quantR = Math.floor(signPow(r / maximumValue, 0.5) * 9 + 9);
  const quantG = Math.floor(signPow(g / maximumValue, 0.5) * 9 + 9);
  const quantB = Math.floor(signPow(b / maximumValue, 0.5) * 9 + 9);

  return quantR * 19 * 19 + quantG * 19 + quantB;
}

function signPow(value: number, exp: number): number {
  const sign = Math.sign(value);
  return sign * (Math.abs(value) ** exp);
}
