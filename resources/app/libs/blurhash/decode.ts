import { linearTosRGB, sRGBToLinear } from '@/libs/blurhash/srgb.ts';

export const decode83 = (str: String) => {
  let value = 0;
  for (let i = 0; i < str.length; i++) {
    value =
      value * 83 +
      [
        '0',
        '1',
        '2',
        '3',
        '4',
        '5',
        '6',
        '7',
        '8',
        '9',
        'A',
        'B',
        'C',
        'D',
        'E',
        'F',
        'G',
        'H',
        'I',
        'J',
        'K',
        'L',
        'M',
        'N',
        'O',
        'P',
        'Q',
        'R',
        'S',
        'T',
        'U',
        'V',
        'W',
        'X',
        'Y',
        'Z',
        'a',
        'b',
        'c',
        'd',
        'e',
        'f',
        'g',
        'h',
        'i',
        'j',
        'k',
        'l',
        'm',
        'n',
        'o',
        'p',
        'q',
        'r',
        's',
        't',
        'u',
        'v',
        'w',
        'x',
        'y',
        'z',
        '#',
        '$',
        '%',
        '*',
        '+',
        ',',
        '-',
        '.',
        ':',
        ';',
        '=',
        '?',
        '@',
        '[',
        ']',
        '^',
        '_',
        '{',
        '|',
        '}',
        '~',
      ].indexOf(str[i]);
  }
  return value;
};

export const decodeDC = (value: number) => {
  const intR = value >> 16;
  const intG = (value >> 8) & 255;
  const intB = value & 255;
  return [sRGBToLinear(intR), sRGBToLinear(intG), sRGBToLinear(intB)];
};

export const decodeAC = (value: number, maximumValue: number) => {
  const divisor = 19 * 19;
  const quantR = Math.floor(value / divisor);
  const quantG = Math.floor(value / 19) % 19;
  const quantB = value % 19;

  // Inline and simplify the sign and power operation to avoid extra function calls.
  const adjustValue = (quant: number) => {
    const sign = quant - 9 < 0 ? -1 : 1; // Directly compute the sign.
    const normalized = (quant - 9) / 9;
    return sign * normalized * normalized * maximumValue; // Use squaring instead of Math.pow for exp=2.
  };

  return [adjustValue(quantR), adjustValue(quantG), adjustValue(quantB)];
};


export const decodeBlurhash = (
  blurhash: string,
  width: number,
  height: number,
  punch: number = 1,
) => {
  const sizeFlag = decode83(blurhash[0]);
  const numY = Math.floor(sizeFlag / 9) + 1;
  const numX = (sizeFlag % 9) + 1;

  const quantisedMaximumValue = decode83(blurhash[1]);
  const maximumValue = ((quantisedMaximumValue + 1) / 166) * punch;

  const colors = Array.from({ length: numX * numY }, (_, i) =>
    i === 0
    ? decodeDC(decode83(blurhash.substring(2, 6)))
    : decodeAC(
      decode83(blurhash.substring(4 + i * 2, 6 + i * 2)),
      maximumValue,
    ),
  );

  const bytesPerRow = width * 4;
  const pixels = new Uint8ClampedArray(bytesPerRow * height);

  const cosX = Array.from({ length: width }, (_, x) =>
    Array.from({ length: numX }, (__, i) => Math.cos((Math.PI * x * i) / width)),
  );
  const cosY = Array.from({ length: height }, (_, y) =>
    Array.from({ length: numY }, (__, j) =>
      Math.cos((Math.PI * y * j) / height),
    ),
  );

  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      let r = 0,
        g = 0,
        b = 0;

      for (let j = 0; j < numY; j++) {
        for (let i = 0; i < numX; i++) {
          const basis = cosX[x][i] * cosY[y][j];
          const color = colors[i + j * numX];
          r += color[0] * basis;
          g += color[1] * basis;
          b += color[2] * basis;
        }
      }

      const offset = y * bytesPerRow + x * 4;
      pixels[offset] = linearTosRGB(r);
      pixels[offset + 1] = linearTosRGB(g);
      pixels[offset + 2] = linearTosRGB(b);
      pixels[offset + 3] = 255; // alpha
    }
  }

  return pixels;
};