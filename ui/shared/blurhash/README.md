# @baander/blurhash

BlurHash encoding/decoding for Baander apps.

## Platform Support

- ✅ Web / Chrome / Firefox / Safari
- ✅ Electron
- ✅ React Native (iOS, Android, macOS, Windows, tvOS)

## Installation

```bash
yarn add @baander/blurhash
```

## Core API (Cross-Platform)

### Encoding

```typescript
import { encode, type PixelData } from '@baander/blurhash';

// Web/Electron: pass image directly
const hash = await encode(imageElement);
const hash = await encode(imageBitmap);
const hash = await encode(canvasElement);

// React Native: pass raw pixel data
const hash = await encode({
  width: 400,
  height: 300,
  data: new Uint8Array(/* RGBA bytes */),
});

// With options
const hash = await encode(source, {
  componentsX: 4,  // 1-9, default 4 (more detail = longer hash)
  componentsY: 3,  // 1-9, default 3
  maxWidth: 64,    // resize before encoding (default 64)
  maxHeight: 64,
});
```

### Decoding

```typescript
import { decode } from '@baander/blurhash';

// Returns raw RGBA pixel data (works on all platforms)
const { width, height, data } = decode('LFE.?D%LtSR5', {
  width: 400,
  height: 300,
  punch: 1,  // Optional contrast boost (default 1)
});

// data is Uint8ClampedArray: [r, g, b, a, r, g, b, a, ...]
```

## Web/Electron Rendering

For web and Electron, import from `@baander/blurhash/web`:

```typescript
import { drawBlurhash, createImageBitmapFromBlurhash, toDataURL } from '@baander/blurhash/web';

// Draw to canvas
const canvas = document.querySelector('canvas');
drawBlurhash('LFE.?D%LtSR5', canvas);

// Create ImageBitmap
const bitmap = await createImageBitmapFromBlurhash('LFE.?D%LtSR5', {
  width: 400,
  height: 300,
});

// Get data URL (for <img> tags)
const dataUrl = await toDataURL('LFE.?D%LtSR5', {
  width: 400,
  height: 300,
});
img.src = dataUrl;
```

## React Native Rendering

React Native doesn't have Canvas, so use the decoded pixel data with a FastImage component:

```typescript
import FastImage from 'react-native-fast-image';
import { decode } from '@baander/blurhash';

function BlurhashImage({ hash, width, height }: Props) {
  const [uri, setUri] = useState<string | null>(null);

  useEffect(() => {
    const { data } = decode(hash, { width, height });

    // Convert to base64 data URI
    const base64 = Buffer.from(data).toString('base64');
    setUri(`data:image/png;base64,${base64}`);
  }, [hash, width, height]);

  return uri ? (
    <FastImage
      source={{ uri }}
      style={{ width, height }}
      resizeMode={FastImage.resizeMode.cover}
    />
  ) : null;
}
```

For better performance in RN, consider using a native module that decodes BlurHash directly on the native side.

## Extracting Pixel Data in React Native

To encode BlurHash in React Native, first extract pixel data from your image:

```typescript
import ImageManipulator from 'expo-image-manipulator';
import { encode } from '@baander/blurhash';

async function imageToBlurhash(uri: string): Promise<string> {
  // Resize and get pixel data
  const { width, height } = await ImageManipulator.manipulateAsync(
    uri,
    [{ resize: { width: 64, height: 64 } }],
    { base64: true },
  );

  const base64Data = width.split(',')[1];
  const buffer = Buffer.from(base64Data, 'base64');

  return encode({
    width: 64,
    height: 64,
    data: new Uint8Array(buffer),
  });
}
```
