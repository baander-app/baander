/**
 * Apply Discrete Cosine Transform (DCT) to a 2D array.
 * Uses the standard DCT-II formula.
 */
export function dct(data: number[], width: number, height: number): Float64Array {
  const result = new Float64Array(width * height);

  for (let u = 0; u < width; u++) {
    for (let v = 0; v < height; v++) {
      let sum = 0;

      for (let x = 0; x < width; x++) {
        for (let y = 0; y < height; y++) {
          sum += data[y * width + x] * cos((x + 0.5) * u * Math.PI / width) * cos((y + 0.5) * v * Math.PI / height);
        }
      }

      result[v * width + u] = sum * 4 / (width * height);
    }
  }

  return result;
}

/**
 * Apply inverse Discrete Cosine Transform (IDCT) to reconstruct the image.
 */
export function idct(data: number[], width: number, height: number): Float64Array {
  const result = new Float64Array(width * height);

  for (let x = 0; x < width; x++) {
    for (let y = 0; y < height; y++) {
      let sum = 0;

      for (let u = 0; u < width; u++) {
        for (let v = 0; v < height; v++) {
          sum += data[v * width + u] * cos((x + 0.5) * u * Math.PI / width) * cos((y + 0.5) * v * Math.PI / height);
        }
      }

      result[y * width + x] = sum;
    }
  }

  return result;
}

function cos(x: number): number {
  return Math.cos(x);
}

/**
 * Multiply AC coefficients by a value to adjust contrast.
 */
export function multiplyBasisFunction(
  pixels: Float32Array,
  width: number,
  height: number,
  basisX: number,
  basisY: number,
): number {
  let sum = 0;

  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const pixel = pixels[y * width + x];
      const basis = Math.cos(Math.PI * basisX * x / width) * Math.cos(Math.PI * basisY * y / height);
      sum += pixel * basis;
    }
  }

  return sum;
}
