/**
 * Web/Electron-specific exports from @baander/shared.
 *
 * This module re-exports cross-platform functionality plus web-specific
 * utilities that use Canvas, ImageBitmap, and other Web APIs.
 *
 * In web or Electron apps:
 *   import { drawBlurhash, createImageBitmapFromBlurhash } from '@baander/shared/web';
 *
 * In React Native apps, use the core decode functions and implement
 * your own rendering with react-native-fast-image or similar.
 */

// Re-export all cross-platform functionality
export * from './index';

// Web-specific BlurHash rendering
export {
  clearCanvas,
  drawBlurhash,
  createImageBitmapFromBlurhash,
  toDataURL,
  toBlob,
} from './blurhash/web';
