export interface Components {
  readonly x: number;
  readonly y: number;
}

export interface BlurhashOptions {
  /**
   * Number of horizontal DCT components (1-9).
   * More components = more detail but longer hash.
   * @default 4
   */
  componentsX?: number;

  /**
   * Number of vertical DCT components (1-9).
   * More components = more detail but longer hash.
   * @default 3
   */
  componentsY?:;
}

export interface EncodeOptions extends BlurhashOptions {
  /**
   * Resize source image to this maximum width before encoding.
   * @default 64
   */
  maxWidth?: number;

  /**
   * Resize source image to this maximum height before encoding.
   * @default 64
   */
  maxHeight?: number;
}

export interface DecodeOptions {
  /**
   * Width of the decoded image.
   */
  width: number;

  /**
   * Height of the decoded image.
   */
  height: number;

  /**
   * Punch value to increase contrast.
   * @default 1
   */
  punch?: number;
}
