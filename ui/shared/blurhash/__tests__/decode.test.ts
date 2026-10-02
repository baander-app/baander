import { describe, expect, it } from 'vitest';
import { decode } from '../decode';

describe('decode', () => {
  it('converts channel values to one RGBA byte per channel', () => {
    const result = decode('000000', { width: 2, height: 1 });

    expect(result.data).toBeInstanceOf(Uint8ClampedArray);
    expect(Array.from(result.data)).toEqual([0, 0, 0, 255, 0, 0, 0, 255]);
  });
});
