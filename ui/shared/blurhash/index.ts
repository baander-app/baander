/**
 * @baander/blurhash -- BlurHash encoding/decoding for Baander apps.
 *
 * Cross-platform core:
 * - Encode: raw pixel data → BlurHash string
 * - Decode: BlurHash string → raw pixel data (Uint8ClampedArray RGBA)
 *
 * Platform-specific rendering:
 * - Web/Electron: import from '@baander/blurhash/web'
 * - React Native: use core decode with react-native-fast-image or similar
 */

export { encode } from './encode';
export { decode, decodeToArray } from './decode';
export type { Components, BlurhashOptions, EncodeOptions, DecodeOptions } from './types';
