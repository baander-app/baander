const CHAR_MAP = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz#$%*+,-.:;=?@[]^_{|}~';

/**
 * Encode a number to base83 string of given length.
 */
export function encode83(value: number, length: number): string {
  let result = '';
  let exp = 1;

  for (let i = 0; i < length; i++) {
    exp *= 83;
  }

  for (let i = 0; i < length; i++) {
    exp /= 83;
    const digit = Math.floor(value / exp);
    result += CHAR_MAP[digit];
    value -= digit * exp;
  }

  return result;
}

/**
 * Decode a base83 string to a number.
 */
export function decode83(str: string): number {
  let result = 0;

  for (let i = 0; i < str.length; i++) {
    const index = CHAR_MAP.indexOf(str[i]);
    if (index === -1) {
      throw new Error(`Invalid base83 character: ${str[i]}`);
    }
    result = result * 83 + index;
  }

  return result;
}

/**
 * Calculate the maximum value that can be encoded in a base83 string of given length.
 */
export function maxBase83(length: number): number {
  return 83 ** length - 1;
}
