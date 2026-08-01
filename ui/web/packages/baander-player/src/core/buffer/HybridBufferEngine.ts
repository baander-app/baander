/**
 * @module core/buffer/HybridBufferEngine
 * @description Hybrid buffer engine that uses MSE (Media Source Extensions) as the
 * primary playback pipeline and falls back to WebCodecs when MSE is unavailable.
 *
 * The backend delivers fMP4/CMAF segments:
 *   - init.mp4: moov box with track metadata (codec, timescale, sample descriptions)
 *   - seg_{N}.m4s: moof+mdat boxes with media samples
 *
 * MSE approach:
 *   1. Create a MediaSource and attach to a <video> element
 *   2. Create SourceBuffers for video and audio tracks
 *   3. Append init.mp4 first (Initialization Segment)
 *   4. Append seg_N.m4s sequentially (Media Segment)
 *   5. Manage buffer eviction when exceeding maxBufferLength
 *
 * WebCodecs approach (fallback):
 *   1. Parse init.mp4 to extract codec config (VideoDecoderConfig / AudioDecoderConfig)
 *   2. Create VideoDecoder + AudioDecoder
 *   3. Decode each segment's moof+mdat into VideoFrames / AudioData
 *   4. Render frames via canvas or OffscreenCanvas
 *   5. Play audio via AudioContext
 *
 * The backend uses HEVC (hvc1) codec. MSE support for HEVC varies by browser:
 *   - Safari: native HEVC MSE support
 *   - Chrome 107+: HEVC MSE with hardware decoder
 *   - Firefox: limited HEVC support
 *
 * Codec string mapping (backend's rfc6381Codec → MIME type):
 *   "hvc1.1.6.L93.B0" → 'video/mp4; codecs="hvc1.1.6.L93.B0"'
 *   "mp4a.40.2"        → 'audio/mp4; codecs="mp4a.40.2"'
 *
 * @see App\Transcode\Domain\ValueObject\QualityTier for codec configuration
 * @see App\Transcode\Interface\Controller\StreamSegmentController for segment delivery
 */

import type {
  BufferBackend,
  BufferStats,
  TimeRange,
  Rendition,
} from '../../types';

// ---------------------------------------------------------------------------
// Buffer Engine Events
// ---------------------------------------------------------------------------

export interface BufferEngineEvents {
  onBufferAppended: (segmentIndex: number, byteLength: number) => void;
  onBufferEvicted: (range: TimeRange) => void;
  onBufferStalled: () => void;
  onBufferFull: () => void;
  onCodecChange: (codecs: string) => void;
  onError: (error: Error) => void;
}

export interface BufferEngineConfig {
  /** Maximum forward buffer in seconds before eviction triggers. */
  maxBufferLength: number;
  /** Maximum total buffer size in MB. */
  maxBufferSize: number;
  /** Start buffering this many seconds ahead of current time. */
  bufferAhead: number;
  /** Seconds of buffer to keep behind the playhead during eviction. */
  behindBuffer: number;
  /** Minimum forward buffer to consider playback viable (seconds). */
  sufficientBufferThreshold: number;
  /** Canvas width for WebCodecs fallback. Default: rendition width. */
  webCodecsCanvasWidth?: number;
  /** Canvas height for WebCodecs fallback. Default: rendition height. */
  webCodecsCanvasHeight?: number;
}

const DEFAULT_BUFFER_CONFIG: BufferEngineConfig = {
  maxBufferLength: 30,
  maxBufferSize: 50,
  bufferAhead: 10,
  behindBuffer: 5,
  sufficientBufferThreshold: 2,
};

// ---------------------------------------------------------------------------
// fMP4 Box Parser
// ---------------------------------------------------------------------------

/**
 * Minimal fMP4/ISO BMFF box parser.
 *
 * Reads box structure (type + size) and extracts codec-specific configuration
 * from the moov box:
 *   - hvcC (HEVC decoder configuration record) from video tracks
 *   - avcC (AVC decoder configuration record) from video tracks
 *   - esds (elementary stream descriptor) from audio tracks
 *   - moof.trun (track fragment run — sample sizes, durations, offsets)
 *   - moof.tfhd (track fragment header — base data offset, default sample values)
 *
 * fMP4 box structure:
 *   [4 bytes size][4 bytes type][payload...]
 *   Full-box: [4 bytes size][4 bytes type][1 byte version][3 bytes flags][payload...]
 */

/** Parsed track information from a moov box. */
export interface ParsedTrack {
  /** Track ID from tkhd. */
  trackId: number;
  /** Handler type from hdlr: 'vide' or 'soun'. */
  handlerType: string;
  /** Timescale from mdhd. */
  timescale: number;
  /** Codec-specific description bytes (hvcC or avcC for video, esds for audio). */
  description: Uint8Array | null;
  /** RFC 6381 codec string extracted from sample description. */
  codecString: string;
}

/** Parsed sample metadata from a moof box. */
export interface ParsedFragment {
  /** Track ID from tfhd. */
  trackId: number;
  /** Base data offset from tfhd. */
  baseDataOffset: number;
  /** Default sample duration from tfhd (0 = per-sample from trun). */
  defaultSampleDuration: number;
  /** Default sample size from tfhd (0 = per-sample from trun). */
  defaultSampleSize: number;
  /** Samples from trun. */
  samples: Array<{
    /** Duration in timescale units. */
    duration: number;
    /** Size in bytes. */
    size: number;
    /** Composition time offset in timescale units. */
    ctsOffset: number;
    /** Whether this sample is a sync point (keyframe). */
    isSync: boolean;
    /** Byte offset of this sample's data within the mdat, relative to mdat start. */
    offset: number;
  }>;
}

/** Result of parsing an init segment (moov box). */
export interface InitSegmentParseResult {
  tracks: ParsedTrack[];
  /** Total duration from mvhd (0 if not present — fragments provide timing). */
  duration: number;
  /** Movie timescale from mvhd. */
  timescale: number;
}

/** Result of parsing a media segment (moof+mdat). */
export interface MediaSegmentParseResult {
  fragments: ParsedFragment[];
  /** Raw mdat data (all sample bytes concatenated in order). */
  mdatData: Uint8Array;
  /** Byte offset where mdat payload starts. */
  mdatPayloadOffset: number;
}

/**
 * DataView helper that reads big-endian integers from a buffer at a given offset.
 */
export class BoxReader {
  private view: DataView;
  private offset: number;

  constructor(buffer: ArrayBuffer | Uint8Array, startOffset = 0) {
    const buf = buffer instanceof Uint8Array
      ? buffer.buffer.slice(buffer.byteOffset, buffer.byteOffset + buffer.byteLength)
      : buffer;
    this.view = new DataView(buf);
    this.offset = startOffset;
  }

  get position(): number { return this.offset; }
  get byteLength(): number { return this.view.byteLength; }

  remaining(): number { return this.view.byteLength - this.offset; }

  seek(pos: number): void { this.offset = pos; }

  skip(bytes: number): void { this.offset += bytes; }

  readUint8(): number {
    const val = this.view.getUint8(this.offset);
    this.offset += 1;
    return val;
  }

  readUint16(): number {
    const val = this.view.getUint16(this.offset);
    this.offset += 2;
    return val;
  }

  readUint32(): number {
    const val = this.view.getUint32(this.offset);
    this.offset += 4;
    return val;
  }

  readInt32(): number {
    const val = this.view.getInt32(this.offset);
    this.offset += 4;
    return val;
  }

  /** Read 4-byte ASCII box type string. */
  readBoxType(): string {
    let s = '';
    for (let i = 0; i < 4; i++) {
      s += String.fromCharCode(this.readUint8());
    }
    return s;
  }

  /** Read bytes as Uint8Array. */
  readBytes(length: number): Uint8Array {
    const bytes = new Uint8Array(this.view.buffer, this.offset, length);
    this.offset += length;
    return bytes;
  }

  /** Read remaining bytes from current position. */
  readRemaining(): Uint8Array {
    return this.readBytes(this.remaining());
  }
}

/**
 * Find a child box of a given type within a parent box's payload.
 * Returns {offset, size} where offset points to the start of the child box.
 */
export function findBox(
  reader: BoxReader,
  parentEnd: number,
  targetType: string,
): { offset: number; size: number; headerSize: number } | null {
  while (reader.position < parentEnd - 7) {
    const boxStart = reader.position;
    let size = reader.readUint32();
    const type = reader.readBoxType();

    if (size === 1) {
      // 64-bit extended size
      const hi = reader.readUint32();
      const lo = reader.readUint32();
      size = hi * 0x100000000 + lo;
    } else if (size === 0) {
      size = parentEnd - boxStart;
    }

    const headerSize = reader.position - boxStart;

    if (type === targetType) {
      return { offset: boxStart, size, headerSize };
    }

    // Skip to next box
    reader.seek(boxStart + size);
  }
  return null;
}

/**
 * Find all child boxes of a given type within a parent box's payload.
 */
export function findAllBoxes(
  reader: BoxReader,
  parentEnd: number,
  targetType: string,
): Array<{ offset: number; size: number; headerSize: number }> {
  const results: Array<{ offset: number; size: number; headerSize: number }> = [];
  while (reader.position < parentEnd - 7) {
    const boxStart = reader.position;
    let size = reader.readUint32();
    const type = reader.readBoxType();

    if (size === 1) {
      const hi = reader.readUint32();
      const lo = reader.readUint32();
      size = hi * 0x100000000 + lo;
    } else if (size === 0) {
      size = parentEnd - boxStart;
    }

    const headerSize = reader.position - boxStart;

    if (type === targetType) {
      results.push({ offset: boxStart, size, headerSize });
    }

    reader.seek(boxStart + size);
  }
  return results;
}

/**
 * Parse an fMP4 init segment (moov box) to extract track codec configurations.
 */
export function parseInitSegment(data: ArrayBuffer): InitSegmentParseResult {
  const reader = new BoxReader(data);
  const result: InitSegmentParseResult = {
    tracks: [],
    duration: 0,
    timescale: 1000,
  };

  // Top-level: find moov
  const moov = findBox(reader, reader.byteLength, 'moov');
  if (!moov) return result;

  // Parse mvhd for duration and timescale
  reader.seek(moov.offset + moov.headerSize);
  const mvhd = findBox(reader, moov.offset + moov.size, 'mvhd');
  if (mvhd) {
    reader.seek(mvhd.offset + mvhd.headerSize);
    reader.readUint8(); // version
    reader.skip(3);     // flags
    reader.skip(4);     // creation_time
    reader.skip(4);     // modification_time
    result.timescale = reader.readUint32();
    if (result.timescale === 0) result.timescale = 1000;
    result.duration = reader.readUint32();
  }

  // Find all trak boxes
  reader.seek(moov.offset + moov.headerSize);
  const traks = findAllBoxes(reader, moov.offset + moov.size, 'trak');

  for (const trak of traks) {
    const track = parseTrack(reader, trak);
    if (track) {
      result.tracks.push(track);
    }
  }

  return result;
}

/**
 * Parse a single trak box to extract codec configuration.
 */
function parseTrack(reader: BoxReader, trak: { offset: number; size: number; headerSize: number }): ParsedTrack | null {
  const trakEnd = trak.offset + trak.size;

  // Find mdia inside trak
  reader.seek(trak.offset + trak.headerSize);
  const mdia = findBox(reader, trakEnd, 'mdia');
  if (!mdia) return null;

  // Find hdlr for handler type
  reader.seek(mdia.offset + mdia.headerSize);
  const hdlr = findBox(reader, mdia.offset + mdia.size, 'hdlr');
  let handlerType = '';
  if (hdlr) {
    reader.seek(hdlr.offset + hdlr.headerSize);
    reader.readUint32(); // version + flags
    reader.skip(4);     // pre_defined
    handlerType = reader.readBoxType();
  }

  // Find mdhd for timescale
  reader.seek(mdia.offset + mdia.headerSize);
  const mdhd = findBox(reader, mdia.offset + mdia.size, 'mdhd');
  let timescale = 1000;
  if (mdhd) {
    reader.seek(mdhd.offset + mdhd.headerSize);
    const version = reader.readUint8();
    reader.skip(3);
    if (version === 1) {
      reader.skip(8); // creation_time
      reader.skip(8); // modification_time
    } else {
      reader.skip(4);
      reader.skip(4);
    }
    timescale = reader.readUint32();
  }

  // Find minf → stbl → stsd → sample entry
  reader.seek(mdia.offset + mdia.headerSize);
  const minf = findBox(reader, mdia.offset + mdia.size, 'minf');
  if (!minf) return null;

  reader.seek(minf.offset + minf.headerSize);
  const stbl = findBox(reader, minf.offset + minf.size, 'stbl');
  if (!stbl) return null;

  reader.seek(stbl.offset + stbl.headerSize);
  const stsd = findBox(reader, stbl.offset + stbl.size, 'stsd');
  if (!stsd) return null;

  reader.seek(stsd.offset + stsd.headerSize);
  reader.readUint32(); // version + flags
  const entryCount = reader.readUint32();
  if (entryCount === 0) return null;

  // First sample entry
  const entryStart = reader.position;
  const entrySize = reader.readUint32();
  const entryType = reader.readBoxType();

  let description: Uint8Array | null = null;
  let codecString = '';

  if (handlerType === 'vide') {
    // Video sample entry: hvc1, hev1, avc1, avc3
    // Skip: reserved(6) + data_reference_index(2) + pre_defined(2) + reserved(2)
    //       + pre_defined(12) + width(2) + height(2) + horiz_resolution(4)
    //       + vert_resolution(4) + reserved(4) + frame_count(2) + compressor_name(32)
    //       + depth(2) + pre_defined(2) = 78 bytes total after type
    reader.seek(entryStart + 8 + 6 + 2 + 2 + 2 + 12 + 2 + 2 + 4 + 4 + 4 + 2 + 32 + 2 + 2);

    const childEnd = entryStart + entrySize;

    if (entryType === 'hvc1' || entryType === 'hev1') {
      // Find hvcC box
      const hvcC = findBox(reader, childEnd, 'hvcC');
      if (hvcC) {
        // Store only the HEVCDecoderConfigurationRecord payload,
        // NOT the ISOBMFF box header. WebCodecs expects the raw config record.
        const payloadSize = hvcC.size - hvcC.headerSize;
        description = new Uint8Array(
          reader['view'].buffer,
          hvcC.offset + hvcC.headerSize,
          payloadSize,
        );
        // Build codec string from hvcC
        codecString = parseHvcCCodec(reader, hvcC.offset + hvcC.headerSize);
      }
    } else if (entryType === 'avc1' || entryType === 'avc3') {
      const avcC = findBox(reader, childEnd, 'avcC');
      if (avcC) {
        // Store only the AVCEncoderConfigurationRecord payload.
        const payloadSize = avcC.size - avcC.headerSize;
        description = new Uint8Array(
          reader['view'].buffer,
          avcC.offset + avcC.headerSize,
          payloadSize,
        );
        codecString = parseAvcCCodec(reader, avcC.offset + avcC.headerSize);
      }
    }
  } else if (handlerType === 'soun') {
    // Audio sample entry: mp4a
    // Skip: reserved(6) + data_reference_index(2) + reserved(8) + channel_count(2)
    //       + sample_size(2) + pre_defined(2) + reserved(2) + sample_rate(4) = 30 bytes
    reader.seek(entryStart + 8 + 6 + 2 + 8 + 2 + 2 + 2 + 2 + 4);

    const childEnd = entryStart + entrySize;
    const esds = findBox(reader, childEnd, 'esds');
    if (esds) {
      // Store the full esds box for reference, but we'll extract
      // the AudioSpecificConfig from it when configuring the decoder.
      description = new Uint8Array(
        reader['view'].buffer,
        esds.offset + esds.headerSize, // skip ISOBMFF box header
        esds.size - esds.headerSize,
      );
      codecString = 'mp4a.40.2'; // AAC-LC — backend default
    }
  }

  // Find tkhd for track ID
  reader.seek(trak.offset + trak.headerSize);
  const tkhd = findBox(reader, trakEnd, 'tkhd');
  let trackId = 0;
  if (tkhd) {
    reader.seek(tkhd.offset + tkhd.headerSize);
    const version = reader.readUint8();
    reader.skip(3); // flags
    if (version === 1) {
      reader.skip(8); // creation_time
      reader.skip(8); // modification_time
    } else {
      reader.skip(4);
      reader.skip(4);
    }
    trackId = reader.readUint32();
  }

  return {
    trackId,
    handlerType,
    timescale,
    description,
    codecString,
  };
}

/**
 * Parse HEVC decoder configuration record (hvcC) to extract codec string.
 * Format: ISO 14496-15 HEVCDecoderConfigurationRecord
 */
export function parseHvcCCodec(reader: BoxReader, payloadOffset: number): string {
  reader.seek(payloadOffset);
  reader.readUint8();  // configurationVersion
  const profileByte = reader.readUint8(); // general_profile_space(2) + tier(1) + profile_idc(5)
  const profileCompatibility = reader.readUint32();
  reader.skip(4); // constraint_indicator_flags (4 bytes, we only read the first 6 bits worth)
  const levelByte = reader.readUint8();
  reader.skip(2); // min_spatial_segmentation_idc (with reserved bits)
  reader.skip(1); // parallelismType (with reserved bits)
  reader.skip(1); // chromaFormat (with reserved bits)
  reader.skip(1); // bitDepthLumaMinus8 (with reserved bits)
  reader.skip(1); // bitDepthChromaMinus8 (with reserved bits)
  reader.readUint16(); // avgFrameRate
  reader.skip(1); // constantFrameRate + numTemporalLayers + temporalIdNested + lengthSizeMinusOne

  const profileSpace = (profileByte >> 6) & 0x03;
  const tier = (profileByte >> 5) & 0x01;
  const profileIdc = profileByte & 0x1F;
  const level = levelByte / 30;

  let codec = 'hvc1';
  if (profileSpace > 0) {
    codec += '.' + String.fromCharCode(65 + profileSpace - 1) + profileIdc;
  } else {
    codec += '.' + profileIdc;
  }

  // Profile compatibility as hex
  const compatHex = profileCompatibility.toString(16).toUpperCase().padStart(8, '0');
  // Take only significant bits — strip leading zeros but keep at least one group
  codec += '.' + compatHex.replace(/^0+/, '') || '0';

  if (tier > 0) {
    codec += '.H'; // High tier
  } else {
    codec += '.L';
  }
  codec += level.toFixed(1);

  return codec;
}

/**
 * Parse AVC decoder configuration record (avcC) to extract codec string.
 */
export function parseAvcCCodec(reader: BoxReader, payloadOffset: number): string {
  reader.seek(payloadOffset);
  reader.readUint8();  // configurationVersion
  const profile = reader.readUint8();
  const compat = reader.readUint8();
  const level = reader.readUint8();

  // avc1.%02x%02x%02x
  return `avc1.${profile.toString(16).padStart(2, '0')}${compat.toString(16).padStart(2, '0')}${level.toString(16).padStart(2, '0')}`;
}

/**
 * Extract AudioSpecificConfig bytes from an esds box payload.
 *
 * The esds (Elementary Stream Descriptor) box contains a nested descriptor
 * structure defined in ISO 14496-1:
 *   ES_Descriptor (tag 0x03)
 *     → DecoderConfigDescriptor (tag 0x04)
 *       → DecoderSpecificInfo (tag 0x05) ← this is the AudioSpecificConfig
 *
 * For AAC-LC, the AudioSpecificConfig is typically 2 bytes:
 *   audioObjectType(5) + samplingFrequencyIndex(4) + channelConfiguration(4) + ... = 2 bytes
 */
export function extractAudioSpecificConfig(esdsPayload: Uint8Array): Uint8Array | null {
  // esds is a FullBox: version(1) + flags(3) = 4 bytes before the descriptor
  if (esdsPayload.length < 5) return null;

  let offset = 4; // skip version + flags

  // Read a descriptor: tag(1) + size(varies, typically 1-4 bytes)
  function readDescriptor(): { tag: number; size: number; dataOffset: number } | null {
    if (offset >= esdsPayload.length) return null;
    const tag = esdsPayload[offset]!;
    offset++;

    // Read descriptor size — can be 1-4 bytes with MSB continuation
    let size = 0;
    for (let i = 0; i < 4; i++) {
      if (offset >= esdsPayload.length) return null;
      const byte = esdsPayload[offset]!;
      offset++;
      size = (size << 7) | (byte & 0x7F);
      if (!(byte & 0x80)) break; // No continuation bit
    }

    return { tag, size, dataOffset: offset };
  }

  // ES_Descriptor (tag 0x03)
  const es = readDescriptor();
  if (!es || es.tag !== 0x03) return null;

  // Skip ES_Descriptor body to find DecoderConfigDescriptor
  // ES_Descriptor contains: ES_ID(2) + streamDependenceFlag(1bit) + ...
  // Minimum: ES_ID(2) + flags(1) = 3 bytes before nested descriptor
  offset = es.dataOffset + 3; // skip ES_ID(2) + stream priority flags(1)

  // DecoderConfigDescriptor (tag 0x04)
  const dcd = readDescriptor();
  if (!dcd || dcd.tag !== 0x04) return null;

  // Skip DecoderConfigDescriptor fixed fields:
  // objectTypeIndication(1) + streamType(6) + upstream(1) + reserved(1) +
  // bufferSizeDB(3) + maxBitrate(4) + avgBitrate(4) = 13 bytes
  offset = dcd.dataOffset + 13;

  // DecoderSpecificInfo (tag 0x05) — this is the AudioSpecificConfig
  const dsi = readDescriptor();
  if (!dsi || dsi.tag !== 0x05) return null;

  return esdsPayload.slice(dsi.dataOffset, dsi.dataOffset + dsi.size);
}

/**
 * Parse an fMP4 media segment (moof + mdat) to extract sample metadata and data.
 */
export function parseMediaSegment(data: ArrayBuffer): MediaSegmentParseResult {
  const reader = new BoxReader(data);
  const fragments: ParsedFragment[] = [];
  let mdatPayloadOffset = 0;
  let mdatData = new Uint8Array(0);

  // Scan top-level boxes
  while (reader.remaining() > 7) {
    const boxStart = reader.position;
    let boxSize = reader.readUint32();
    const boxType = reader.readBoxType();

    if (boxSize === 1) {
      const hi = reader.readUint32();
      const lo = reader.readUint32();
      boxSize = hi * 0x100000000 + lo;
    } else if (boxSize === 0) {
      boxSize = reader.byteLength - boxStart;
    }

    const headerSize = reader.position - boxStart;

    if (boxType === 'moof') {
      const fragment = parseMoof(reader, boxStart, boxSize, headerSize);
      if (fragment) {
        fragments.push(fragment);
      }
    } else if (boxType === 'mdat') {
      mdatPayloadOffset = boxStart + headerSize;
      const payloadLength = boxSize - headerSize;
      if (payloadLength > 0) {
        mdatData = new Uint8Array(data, mdatPayloadOffset, payloadLength);
      }
    }

    reader.seek(boxStart + boxSize);
  }

  return { fragments, mdatData, mdatPayloadOffset };
}

/**
 * Parse a moof box to extract track fragment information.
 */
function parseMoof(
  reader: BoxReader,
  moofStart: number,
  moofSize: number,
  _moofHeaderSize: number,
): ParsedFragment | null {
  const moofEnd = moofStart + moofSize;
  const moofPayload = moofStart + _moofHeaderSize;

  // Find tfhd
  reader.seek(moofPayload);
  const tfhd = findBox(reader, moofEnd, 'tfhd');
  if (!tfhd) return null;

  reader.seek(tfhd.offset + tfhd.headerSize);
  reader.readUint32(); // version(1) + flags(3)
  const flags = (reader['view'].getUint32(reader.position - 4) & 0x00FFFFFF);
  const trackId = reader.readUint32();

  let baseDataOffset = 0;
  let defaultSampleDuration = 0;
  let defaultSampleSize = 0;

  if (flags & 0x000001) { // base-data-offset-present
    baseDataOffset = reader.readUint32() * 0x100000000 + reader.readUint32();
  }
  if (flags & 0x000008) { // default-sample-duration-present
    defaultSampleDuration = reader.readUint32();
  }
  if (flags & 0x000010) { // default-sample-size-present
    defaultSampleSize = reader.readUint32();
  }

  // Find trun
  reader.seek(moofPayload);
  const trun = findBox(reader, moofEnd, 'trun');
  if (!trun) {
    return { trackId, baseDataOffset, defaultSampleDuration, defaultSampleSize, samples: [] };
  }

  reader.seek(trun.offset + trun.headerSize);
  const rawFlags = reader.readUint32();
  const trunFlagsValue = rawFlags & 0x00FFFFFF;
  const _trunVersion = (rawFlags >> 24) & 0xFF;
  void _trunVersion;

  const sampleCount = reader.readUint32();

  let dataOffset = 0;
  if (trunFlagsValue & 0x000001) { // data-offset-present
    dataOffset = reader.readInt32();
  }

  // Skip first_sample_flags if present
  if (trunFlagsValue & 0x000004) { // first-sample-flags-present
    reader.skip(4);
  }

  const hasDuration = !!(trunFlagsValue & 0x000100);
  const hasSize = !!(trunFlagsValue & 0x000200);
  const hasFlags = !!(trunFlagsValue & 0x000400);
  const hasCtsOffset = !!(trunFlagsValue & 0x000800);

  let runningOffset = baseDataOffset + dataOffset;

  const samples: ParsedFragment['samples'] = [];
  for (let i = 0; i < sampleCount; i++) {
    const duration = hasDuration ? reader.readUint32() : defaultSampleDuration;
    const size = hasSize ? reader.readUint32() : defaultSampleSize;
    let sampleFlags = 0;
    if (hasFlags) {
      sampleFlags = reader.readUint32();
    } else if (i === 0 && (trunFlagsValue & 0x000004)) {
      // first-sample-flags was already consumed above
    }
    let ctsOffset = 0;
    if (hasCtsOffset) {
      ctsOffset = _trunVersion === 0 ? reader.readUint32() : reader.readInt32();
    }

    const isSync = !(sampleFlags & (1 << 16)); // sample_is_non_sync_sample is bit 16 of 32-bit flags

    samples.push({
      duration,
      size,
      ctsOffset,
      isSync,
      offset: runningOffset - (baseDataOffset || 0),
    });

    runningOffset += size;
  }

  return {
    trackId,
    baseDataOffset: baseDataOffset || (baseDataOffset + dataOffset),
    defaultSampleDuration,
    defaultSampleSize,
    samples,
  };
}

// ---------------------------------------------------------------------------
// MSE Implementation
// ---------------------------------------------------------------------------

/**
 * MSE-backed buffer engine.
 *
 * Flow:
 * 1. new MSEBufferEngine(videoElement, config, events)
 * 2. init(rendition) — creates MediaSource, SourceBuffers
 * 3. appendInit(data) — appends the init.mp4
 * 4. appendSegment(index, data) — appends seg_N.m4s
 * 5. seekTo(time) — evicts old buffer, prepares for refill
 * 6. destroy() — clean up
 */
class MSEBufferEngine {
  private mediaSource: MediaSource | null = null;
  private videoSourceBuffer: SourceBuffer | null = null;
  private audioSourceBuffer: SourceBuffer | null = null;
  /** Tracks the current combined MIME type for CMAF seamless switching. */
  private currentMime = '';
  /** Whether the current stream is muxed (video+audio in one buffer). */
  private isMuxed = false;
  private appendQueue: Array<{
    buffer: SourceBuffer;
    data: ArrayBuffer;
    resolve: () => void;
    reject: (err: Error) => void;
  }> = [];
  private isAppending = false;
  private initialized = false;
  private segmentMap = new Map<number, TimeRange>();
  private totalBytesBuffered = 0;
  private objectUrl = '';
  private audioQueue: ArrayBuffer[] = [];
  private audioQueueResolve: Array<{ resolve: () => void; reject: (err: Error) => void }> = [];

  constructor(
    private readonly videoElement: HTMLVideoElement,
    private readonly config: BufferEngineConfig,
    private readonly events: BufferEngineEvents,
  ) {}

  async init(rendition: Rendition): Promise<void> {
    // OPP-03: CMAF seamless rendition switching — if MediaSource already exists
    // and the codec string matches, skip reinitialization entirely.
    const videoCodec = rendition.codecs.split(',')[0] ?? 'hvc1.1.6.L93.B0';
    const audioCodec = rendition.codecs.split(',')[1] ?? 'mp4a.40.2';
    const combinedMime = `video/mp4; codecs="${videoCodec},${audioCodec}"`;
    const videoOnlyMime = `video/mp4; codecs="${videoCodec}"`;

    if (
      this.mediaSource &&
      this.mediaSource.readyState === 'open' &&
      this.videoSourceBuffer &&
      (this.currentMime === combinedMime || this.currentMime === videoOnlyMime)
    ) {
      // Same codecs — no reinitialization needed for CMAF rendition switch.
      return;
    }

    if (this.mediaSource) {
      await this.destroy();
    }

    this.mediaSource = new MediaSource();
    this.objectUrl = URL.createObjectURL(this.mediaSource);

    return new Promise((resolve, reject) => {
      if (!this.mediaSource) {
        reject(new Error('MediaSource creation failed'));
        return;
      }

      this.mediaSource.addEventListener('sourceopen', () => {
        try {
          if (!this.mediaSource || this.mediaSource.readyState !== 'open') {
            reject(new Error('MediaSource not open'));
            return;
          }

          // MISSING-04: Detect muxed content.
          // The backend produces muxed fMP4 (video+audio in one init.mp4).
          // Try combined MIME first for muxed, fall back to video-only.
          let selectedMime = '';
          this.isMuxed = false;

          // Try combined codecs first (muxed fMP4)
          if (MediaSource.isTypeSupported(combinedMime)) {
            selectedMime = combinedMime;
            this.isMuxed = true;
          } else if (MediaSource.isTypeSupported(videoOnlyMime)) {
            selectedMime = videoOnlyMime;
          } else {
            // Try AVC fallback for browsers without HEVC MSE support
            const avcMime = 'video/mp4; codecs="avc1.640028,mp4a.40.2"';
            const avcOnlyMime = 'video/mp4; codecs="avc1.640028"';
            if (MediaSource.isTypeSupported(avcMime)) {
              selectedMime = avcMime;
              this.isMuxed = true;
            } else if (MediaSource.isTypeSupported(avcOnlyMime)) {
              selectedMime = avcOnlyMime;
            } else {
              reject(new Error(`No supported video codec. Tried: ${combinedMime}, ${videoOnlyMime}, ${avcMime}, ${avcOnlyMime}`));
              return;
            }
          }

          this.videoSourceBuffer = this.mediaSource.addSourceBuffer(selectedMime);
          this.videoSourceBuffer.mode = 'segments';
          this.currentMime = selectedMime;

          this.videoSourceBuffer.addEventListener('error', () => {
            this.events.onError(new Error('Video SourceBuffer error'));
          });

          this.events.onCodecChange(videoCodec);

          // Only create a separate audio SourceBuffer if the stream is demuxed
          // (audio-only AdaptationSet). For muxed streams, the combined MIME
          // handles both tracks in the single videoSourceBuffer.
          this.audioSourceBuffer = null;

          this.videoElement.src = this.objectUrl;
          resolve();
        } catch (err) {
          reject(err instanceof Error ? err : new Error(String(err)));
        }
      }, { once: true });

      this.mediaSource.addEventListener('error', () => {
        reject(new Error('MediaSource error event'));
      }, { once: true });
    });
  }

  /** Append the init segment (fMP4 moov box). Must be called before appendSegment. */
  async appendInit(data: ArrayBuffer): Promise<void> {
    if (!this.videoSourceBuffer) {
      throw new Error('Buffer not initialized');
    }
    await this.appendToArrayBuffer(this.videoSourceBuffer, data);
    this.initialized = true;
  }

  /** Append a media segment (fMP4 moof+mdat). */
  async appendSegment(segmentIndex: number, data: ArrayBuffer): Promise<void> {
    if (!this.videoSourceBuffer || !this.initialized) {
      throw new Error('Buffer not initialized');
    }

    await this.appendToArrayBuffer(this.videoSourceBuffer, data);
    this.totalBytesBuffered += data.byteLength;

    // Track segment time range
    this.updateSegmentRange(segmentIndex);

    this.events.onBufferAppended(segmentIndex, data.byteLength);

    // Evict old buffer if we exceed limits
    this.evictIfNeeded();
  }

  /** Get current buffer stats. */
  getStats(): BufferStats {
    const bufferedRanges = this.getBufferedRanges();
    const currentTime = this.videoElement.currentTime;
    let forwardBuffer = 0;

    for (const range of bufferedRanges) {
      if (range.start <= currentTime && range.end > currentTime) {
        forwardBuffer = range.end - currentTime;
        break;
      }
    }

    return {
      forwardBuffer,
      bufferedRanges,
      segmentCount: this.segmentMap.size,
      bytesBuffered: this.totalBytesBuffered,
    };
  }

  /** Check if the buffer has enough data to start playback. */
  hasSufficientBuffer(): boolean {
    const stats = this.getStats();
    return stats.forwardBuffer >= this.config.sufficientBufferThreshold;
  }

  /** Handle seek — remove old buffer ranges to allow refill at new position. */
  async seekTo(time: number): Promise<void> {
    const sb = this.videoSourceBuffer;
    if (!sb || sb.buffered.length === 0) return;

    // Remove all buffered ranges that are more than [behindBuffer] seconds
    // before the seek target. This frees SourceBuffer capacity for new data.
    const keepBehind = Math.max(0, time - this.config.behindBuffer);

    const rangesToRemove: TimeRange[] = [];
    for (let i = 0; i < sb.buffered.length; i++) {
      const rangeStart = sb.buffered.start(i);
      const rangeEnd = sb.buffered.end(i);

      if (rangeEnd <= keepBehind) {
        // Entire range is behind the target — remove it all
        rangesToRemove.push({ start: rangeStart, end: rangeEnd });
      } else if (rangeStart < keepBehind) {
        // Range straddles the cutoff — remove the portion behind
        rangesToRemove.push({ start: rangeStart, end: keepBehind });
      }

      // Also remove ranges far ahead of the seek target
      if (rangeStart > time + this.config.maxBufferLength) {
        rangesToRemove.push({ start: rangeStart, end: rangeEnd });
      }
    }

    for (const range of rangesToRemove) {
      try {
        await this.removeRange(sb, range.start, range.end);
        this.events.onBufferEvicted(range);
      } catch {
        // SourceBuffer may be in updating state — the scheduler will retry
      }
    }

    // Clear segment map — scheduler will refill from the new position
    this.segmentMap.clear();
  }

  /** Append the audio init segment to the audio source buffer. */
  async appendAudioInit(data: ArrayBuffer): Promise<void> {
    // Ensure audio source buffer exists
    this.ensureAudioSourceBuffer();

    if (this.audioSourceBuffer) {
      await this.appendToArrayBuffer(this.audioSourceBuffer, data);
    }
  }

  /** Append an audio media segment to the audio source buffer. */
  async appendAudioSegment(data: ArrayBuffer): Promise<void> {
    this.ensureAudioSourceBuffer();

    if (!this.audioSourceBuffer) return;

    return new Promise((resolve, reject) => {
      this.audioQueue.push(data);
      this.audioQueueResolve.push({ resolve, reject });
      this.processAudioQueue();
    });
  }

  /** Ensure the audio source buffer is created. */
  private ensureAudioSourceBuffer(): void {
    if (this.audioSourceBuffer || !this.mediaSource || this.mediaSource.readyState !== 'open') return;

    try {
      this.audioSourceBuffer = this.mediaSource.addSourceBuffer('audio/mp4; codecs="mp4a.40.2"');
      this.audioSourceBuffer.mode = 'segments';
      this.audioSourceBuffer.addEventListener('updateend', () => this.processAudioQueue());
    } catch {
      // Audio source buffer may already exist or codec not supported
    }
  }

  /** Drain the audio append queue. */
  private processAudioQueue(): void {
    if (
      !this.audioSourceBuffer ||
      this.audioSourceBuffer.updating ||
      this.audioQueue.length === 0
    ) {
      return;
    }

    const data = this.audioQueue.shift()!;
    const { resolve, reject } = this.audioQueueResolve.shift()!;

    const onUpdated = () => {
      cleanup();
      resolve();
      this.processAudioQueue();
    };

    const onError = () => {
      cleanup();
      reject(new Error('Audio SourceBuffer append error'));
      this.processAudioQueue();
    };

    const cleanup = () => {
      this.audioSourceBuffer!.removeEventListener('update', onUpdated);
      this.audioSourceBuffer!.removeEventListener('error', onError);
    };

    this.audioSourceBuffer.addEventListener('update', onUpdated, { once: true });
    this.audioSourceBuffer.addEventListener('error', onError, { once: true });

    try {
      this.audioSourceBuffer.appendBuffer(data);
    } catch (err) {
      cleanup();
      reject(err instanceof Error ? err : new Error(String(err)));
      this.processAudioQueue();
    }
  }

  /** Get the buffered time ranges. */
  getBufferedRanges(): TimeRange[] {
    const ranges: TimeRange[] = [];
    const sb = this.videoSourceBuffer;
    if (!sb) return ranges;

    for (let i = 0; i < sb.buffered.length; i++) {
      ranges.push({
        start: sb.buffered.start(i),
        end: sb.buffered.end(i),
      });
    }
    return ranges;
  }

  /** Destroy the MSE pipeline. */
  async destroy(): Promise<void> {
    this.appendQueue = [];
    this.isAppending = false;

    try {
      if (this.videoSourceBuffer && this.mediaSource?.readyState === 'open') {
        this.mediaSource.removeSourceBuffer(this.videoSourceBuffer);
      }
      if (this.audioSourceBuffer && this.mediaSource?.readyState === 'open') {
        this.mediaSource.removeSourceBuffer(this.audioSourceBuffer);
      }
      if (this.mediaSource?.readyState === 'open') {
        this.mediaSource.endOfStream();
      }
    } catch {
      // Ignore errors during cleanup
    }

    if (this.objectUrl) {
      URL.revokeObjectURL(this.objectUrl);
      this.objectUrl = '';
    }

    // MISSING-05: Clear video element reference to the revoked blob URL
    this.videoElement.removeAttribute('src');
    this.videoElement.load();

    this.videoSourceBuffer = null;
    this.audioSourceBuffer = null;
    this.mediaSource = null;
    this.initialized = false;
    this.segmentMap.clear();
    this.totalBytesBuffered = 0;
    this.currentMime = '';
    this.isMuxed = false;
  }

  // -----------------------------------------------------------------------
  // Private: Append Queue
  // -----------------------------------------------------------------------

  private appendToArrayBuffer(
    buffer: SourceBuffer,
    data: ArrayBuffer,
  ): Promise<void> {
    return new Promise((resolve, reject) => {
      this.appendQueue.push({ buffer, data, resolve, reject });
      this.processAppendQueue();
    });
  }

  private processAppendQueue(): void {
    if (this.isAppending || this.appendQueue.length === 0) return;

    const item = this.appendQueue.shift()!;
    this.isAppending = true;

    const onUpdated = () => {
      cleanup();
      this.isAppending = false;
      item.resolve();
      this.processAppendQueue();
    };

    const onError = () => {
      cleanup();
      this.isAppending = false;
      item.reject(new Error('SourceBuffer append error'));
      this.processAppendQueue();
    };

    const cleanup = () => {
      item.buffer.removeEventListener('update', onUpdated);
      item.buffer.removeEventListener('error', onError);
    };

    item.buffer.addEventListener('update', onUpdated, { once: true });
    item.buffer.addEventListener('error', onError, { once: true });

    try {
      item.buffer.appendBuffer(item.data);
    } catch (err) {
      cleanup();
      this.isAppending = false;

      // OPP-09: On QuotaExceededError, evict behind current time and retry once.
      if (err instanceof DOMException && err.name === 'QuotaExceededError') {
        this.evictForQuota().then(() => {
          // Re-queue the failed item at the front
          this.appendQueue.unshift({
            buffer: item.buffer,
            data: item.data,
            resolve: item.resolve,
            reject: item.reject,
          });
          this.processAppendQueue();
        }).catch(() => {
          item.reject(err);
          this.processAppendQueue();
        });
        return;
      }

      item.reject(err instanceof Error ? err : new Error(String(err)));
      this.processAppendQueue();
    }
  }

  /** Remove a time range from a SourceBuffer, waiting for the update to complete. */
  private removeRange(sb: SourceBuffer, start: number, end: number): Promise<void> {
    return new Promise((resolve, reject) => {
      if (sb.updating) {
        // Wait for current update to finish, then remove
        sb.addEventListener('update', () => {
          this.doRemove(sb, start, end).then(resolve).catch(reject);
        }, { once: true });
        return;
      }
      this.doRemove(sb, start, end).then(resolve).catch(reject);
    });
  }

  private doRemove(sb: SourceBuffer, start: number, end: number): Promise<void> {
    return new Promise((resolve, reject) => {
      const onUpdated = () => {
        sb.removeEventListener('update', onUpdated);
        sb.removeEventListener('error', onError);
        resolve();
      };
      const onError = () => {
        sb.removeEventListener('update', onUpdated);
        sb.removeEventListener('error', onError);
        reject(new Error(`SourceBuffer remove error: ${start}-${end}`));
      };
      sb.addEventListener('update', onUpdated, { once: true });
      sb.addEventListener('error', onError, { once: true });
      try {
        sb.remove(start, end);
      } catch (err) {
        sb.removeEventListener('update', onUpdated);
        sb.removeEventListener('error', onError);
        reject(err);
      }
    });
  }

  private updateSegmentRange(segmentIndex: number): void {
    const sb = this.videoSourceBuffer;
    if (!sb || sb.buffered.length === 0) return;

    // Get the latest buffered range as the segment's time range
    const lastIdx = sb.buffered.length - 1;
    this.segmentMap.set(segmentIndex, {
      start: sb.buffered.start(lastIdx),
      end: sb.buffered.end(lastIdx),
    });
  }

  private evictIfNeeded(): void {
    const sb = this.videoSourceBuffer;
    if (!sb || sb.buffered.length === 0) return;

    const currentTime = this.videoElement.currentTime;
    const buffered = sb.buffered;

    // Calculate forward buffer
    let forwardBuffer = 0;
    for (let i = 0; i < buffered.length; i++) {
      if (buffered.start(i) <= currentTime && buffered.end(i) > currentTime) {
        forwardBuffer = buffered.end(i) - currentTime;
        break;
      }
    }

    if (forwardBuffer > this.config.maxBufferLength) {
      // Evict buffer behind current playback position
      const evictionEnd = currentTime - this.config.behindBuffer;
      if (evictionEnd > 0 && !sb.updating) {
        try {
          sb.remove(0, evictionEnd);
          this.events.onBufferEvicted({ start: 0, end: evictionEnd });
        } catch {
          // Buffer might be updating — ignore
        }
      }
    }

    // Check total buffer size
    const totalMB = this.totalBytesBuffered / (1024 * 1024);
    if (totalMB > this.config.maxBufferSize) {
      this.events.onBufferFull();
    }
  }

  /**
   * Aggressively evict buffer to free quota after a QuotaExceededError.
   * Removes everything behind the current playback position, then tries
   * removing up to half the forward buffer if still needed.
   */
  private async evictForQuota(): Promise<void> {
    const sb = this.videoSourceBuffer;
    if (!sb || sb.buffered.length === 0) return;

    const currentTime = this.videoElement.currentTime;

    // Remove everything behind the playhead
    for (let i = 0; i < sb.buffered.length; i++) {
      const start = sb.buffered.start(i);
      const end = sb.buffered.end(i);

      if (end <= currentTime) {
        try { await this.removeRange(sb, start, end); } catch { /* ignore */ }
      } else if (start < currentTime) {
        try { await this.removeRange(sb, start, currentTime); } catch { /* ignore */ }
      }
    }

    // If SourceBuffer is still under pressure, remove forward buffer
    // beyond maxBufferLength / 2 to free more space
    const keepAhead = this.config.maxBufferLength / 2;
    const cutoff = currentTime + keepAhead;
    for (let i = sb.buffered.length - 1; i >= 0; i--) {
      const start = sb.buffered.start(i);
      const end = sb.buffered.end(i);
      if (start > cutoff) {
        try { await this.removeRange(sb, start, end); } catch { /* ignore */ }
      } else if (end > cutoff) {
        try { await this.removeRange(sb, cutoff, end); } catch { /* ignore */ }
      }
    }

    // Recalculate total bytes
    this.totalBytesBuffered = 0;
    for (let i = 0; i < sb.buffered.length; i++) {
      this.totalBytesBuffered += (sb.buffered.end(i) - sb.buffered.start(i)) * 500_000; // rough estimate
    }
  }
}

// ---------------------------------------------------------------------------
// WebCodecs Fallback (Full Implementation)
// ---------------------------------------------------------------------------

/**
 * WebCodecs-based buffer engine for environments without MSE.
 *
 * Decodes fMP4 segments directly using VideoDecoder and AudioDecoder APIs,
 * rendering video to a canvas and playing audio through AudioContext.
 */
class WebCodecsBufferEngine {
  private videoDecoder: VideoDecoder | null = null;
  private audioDecoder: AudioDecoder | null = null;
  private canvas: OffscreenCanvas | HTMLCanvasElement | null = null;
  private initialized = false;
  private decodersConfigured = false;

  // Track info extracted from init.mp4
  private videoTrack: ParsedTrack | null = null;
  private audioTrack: ParsedTrack | null = null;

  // Buffer tracking
  private totalBytesBuffered = 0;
  private segmentCount = 0;
  private decodedRanges: TimeRange[] = [];
  private lastDecodedTime = 0;

  // Audio playback
  private audioContext: AudioContext | null = null;
  private audioNextStartTime = 0;

  // Timing — used to convert sample durations (in timescale units) to seconds
  private videoTimescale = 90000;
  private audioTimescale = 48000;

  // Presentation time tracking for decoded frames
  private videoBaseTimestamp = -1;
  /** Accumulated presentation time in microseconds across segments. */
  private webCodecsAccumulatedTimeUs = 0;

  constructor(
    private readonly config: BufferEngineConfig,
    private readonly events: BufferEngineEvents,
  ) {}

  async init(rendition: Rendition, canvas: OffscreenCanvas | HTMLCanvasElement): Promise<void> {
    this.canvas = canvas;

    // Parse codec config from rendition
    const videoCodec = rendition.codecs.split(',')[0] ?? 'hvc1.1.6.L93.B0';
    const audioCodec = rendition.codecs.split(',')[1] ?? 'mp4a.40.2';

    // Check WebCodecs support
    if (typeof VideoDecoder === 'undefined') {
      throw new Error('WebCodecs VideoDecoder not available');
    }

    // Create decoders — they will be configured after parsing init.mp4
    this.videoDecoder = new VideoDecoder({
      output: (frame) => this.renderFrame(frame),
      error: (err) => this.events.onError(err instanceof Error ? err : new Error(String(err))),
    });

    this.audioDecoder = new AudioDecoder({
      output: (data) => this.playAudio(data),
      error: (err) => this.events.onError(err instanceof Error ? err : new Error(String(err))),
    });

    // Store codec strings for later configuration
    this.videoCodecString = this.toWebCodecsCodec(videoCodec);
    this.audioCodecString = audioCodec;

    // Initialize AudioContext for audio output
    try {
      this.audioContext = new AudioContext({
        sampleRate: 48000,
        latencyHint: 'playback',
      });
    } catch {
      this.audioContext = null;
    }

    this.initialized = true;
  }

  /**
   * Convert RFC 6381 codec string to WebCodecs codec string.
   * WebCodecs uses the same RFC 6381 strings for most codecs,
   * but HEVC needs special handling.
   */
  private videoCodecString = 'hev1.1.6.L93.B0';
  private audioCodecString = 'mp4a.40.2';

  private toWebCodecsCodec(rfc6381: string): string {
    // WebCodecs accepts RFC 6381 codec strings directly for most codecs.
    // For HEVC: 'hvc1.X.X.X.X.B0' or 'hev1.X.X.X.X.B0' — both accepted.
    // The key difference: WebCodecs needs 'hev1' not 'hvc1' for the
    // description-based configuration (since we extract hvcC from init.mp4).
    if (rfc6381.startsWith('hvc1')) {
      return rfc6381.replace('hvc1', 'hev1');
    }
    return rfc6381;
  }

  /**
   * Append the init segment (moov box).
   * Parses the box to extract codec configurations and configures decoders.
   */
  async appendInit(data: ArrayBuffer): Promise<void> {
    const parseResult = parseInitSegment(data);

    // Find video and audio tracks
    for (const track of parseResult.tracks) {
      if (track.handlerType === 'vide') {
        this.videoTrack = track;
        this.videoTimescale = track.timescale || 90000;
      } else if (track.handlerType === 'soun') {
        this.audioTrack = track;
        this.audioTimescale = track.timescale || 48000;
      }
    }

    // Configure video decoder with extracted hvcC/avcC description
    if (this.videoDecoder && this.videoTrack?.description) {
      const config: VideoDecoderConfig = {
        codec: this.videoCodecString,
        description: this.videoTrack.description,
        optimizeForLatency: true,
      };

      // Verify support before configuring
      const support = await VideoDecoder.isConfigSupported(config);
      if (!support.supported) {
        // Try without description — some decoders can handle it
        const fallbackConfig: VideoDecoderConfig = {
          codec: this.videoCodecString,
          optimizeForLatency: true,
        };
        const fallbackSupport = await VideoDecoder.isConfigSupported(fallbackConfig);
        if (fallbackSupport.supported) {
          this.videoDecoder.configure(fallbackConfig);
        } else {
          throw new Error(`WebCodecs does not support codec: ${this.videoCodecString}`);
        }
      } else {
        this.videoDecoder.configure(config);
      }
      this.events.onCodecChange(this.videoCodecString);
    }

    // Configure audio decoder
    if (this.audioDecoder && this.audioTrack?.description) {
      // Extract AudioSpecificConfig from the esds descriptor payload.
      // WebCodecs needs the raw AudioSpecificConfig bytes, not the full esds box.
      const audioSpecificConfig = extractAudioSpecificConfig(this.audioTrack.description);

      const audioConfig: AudioDecoderConfig = {
        codec: this.audioCodecString,
        description: audioSpecificConfig ?? this.audioTrack.description,
        sampleRate: this.audioTimescale,
        numberOfChannels: 2,
      };

      const support = await AudioDecoder.isConfigSupported(audioConfig);
      if (support.supported) {
        this.audioDecoder.configure(audioConfig);
      }
    }

    this.decodersConfigured = true;
    this.videoBaseTimestamp = -1;
  }

  /**
   * Append a media segment (moof + mdat).
   * Parses the segment to extract sample metadata and feeds each sample
   * to the appropriate decoder as an Encoded{Video,Audio}Chunk.
   */
  async appendSegment(segmentIndex: number, data: ArrayBuffer): Promise<void> {
    if (!this.decodersConfigured) {
      throw new Error('Decoders not configured — appendInit() must be called first');
    }

    const parseResult = parseMediaSegment(data);
    this.totalBytesBuffered += data.byteLength;
    this.segmentCount = segmentIndex + 1;

    for (const fragment of parseResult.fragments) {
      const isVideo = this.videoTrack && fragment.trackId === this.videoTrack.trackId;
      const isAudio = this.audioTrack && fragment.trackId === this.audioTrack.trackId;

      const timescale = isVideo ? this.videoTimescale
        : isAudio ? this.audioTimescale
        : 1000;

      let sampleOffset = 0;
      for (const sample of fragment.samples) {
        // Extract sample bytes from mdat
        const sampleData = parseResult.mdatData.slice(
          sample.offset,
          sample.offset + sample.size,
        );

        // Convert timestamp to microseconds for WebCodecs.
        // Use accumulated base time so timestamps are monotonically
        // increasing across segments, not restarting at 0 for each one.
        const sampleTimeUs = Math.round((sampleOffset / timescale) * 1_000_000);
        const timestampUs = this.webCodecsAccumulatedTimeUs + sampleTimeUs;
        const durationUs = Math.round((sample.duration / timescale) * 1_000_000);

        // Initialize base timestamp on first sample
        if (this.videoBaseTimestamp < 0 && isVideo) {
          this.videoBaseTimestamp = timestampUs;
        }

        if (isVideo && this.videoDecoder?.state === 'configured') {
          const chunk = new EncodedVideoChunk({
            type: sample.isSync ? 'key' : 'delta',
            timestamp: timestampUs,
            duration: durationUs,
            data: sampleData,
          });
          this.videoDecoder.decode(chunk);
        } else if (isAudio && this.audioDecoder?.state === 'configured') {
          const chunk = new EncodedAudioChunk({
            type: sample.isSync ? 'key' : 'delta',
            timestamp: timestampUs,
            duration: durationUs,
            data: sampleData,
          });
          this.audioDecoder.decode(chunk);
        }

        sampleOffset += sample.duration;
      }

      // Accumulate this fragment's total duration so the next segment
      // starts at the correct presentation time.
      const fragmentDurationUs = Math.round((sampleOffset / timescale) * 1_000_000);
      if (isVideo || (fragment.trackId === 0 && !isAudio)) {
        // Track time via the primary (video) track to avoid double-counting
        this.webCodecsAccumulatedTimeUs += fragmentDurationUs;
      }
    }

    // Update decoded time range tracking
    const segmentDuration = this.computeSegmentDuration(parseResult);
    if (segmentDuration > 0) {
      const rangeStart = this.lastDecodedTime;
      this.lastDecodedTime += segmentDuration;
      this.addDecodedRange(rangeStart, this.lastDecodedTime);
    }

    this.events.onBufferAppended(segmentIndex, data.byteLength);
  }

  /** Render a decoded video frame to the canvas. */
  private renderFrame(frame: VideoFrame): void {
    if (!this.canvas) {
      frame.close();
      return;
    }

    try {
      // Resize canvas to match frame if needed
      if (this.canvas.width !== frame.displayWidth || this.canvas.height !== frame.displayHeight) {
        if (this.canvas instanceof OffscreenCanvas) {
          this.canvas.width = frame.displayWidth;
          this.canvas.height = frame.displayHeight;
        }
      }

      const ctx = this.canvas.getContext('2d') as CanvasRenderingContext2D | null;
      if (ctx) {
        ctx.drawImage(frame, 0, 0, frame.displayWidth, frame.displayHeight);
      }
    } finally {
      frame.close();
    }
  }

  /**
   * Play decoded audio data through AudioContext.
   *
   * Converts AudioData to a Float32Array (interleaved PCM) and schedules
   * it as an AudioBufferSourceNode at the correct time.
   */
  private playAudio(data: AudioData): void {
    if (!this.audioContext) {
      data.close();
      return;
    }

    try {
      const numberOfChannels = data.numberOfChannels;
      const numberOfFrames = data.numberOfFrames;
      const sampleRate = data.sampleRate;

      // Create AudioBuffer with the right format
      const audioBuffer = this.audioContext.createBuffer(
        numberOfChannels,
        numberOfFrames,
        sampleRate,
      );

      // Copy each channel's data
      for (let channel = 0; channel < numberOfChannels; channel++) {
        const channelData = new Float32Array(numberOfFrames);
        data.copyTo(channelData, { planeIndex: channel });
        audioBuffer.copyToChannel(channelData, channel);
      }

      // Schedule playback
      const source = this.audioContext.createBufferSource();
      source.buffer = audioBuffer;
      source.connect(this.audioContext.destination);

      // Determine start time — schedule sequentially
      const now = this.audioContext.currentTime;
      const startTime = Math.max(now, this.audioNextStartTime);
      source.start(startTime);

      // Track when the next buffer should start
      this.audioNextStartTime = startTime + numberOfFrames / sampleRate;
    } catch {
      // Audio scheduling may fail during seeks / state changes
    } finally {
      data.close();
    }
  }

  /** Compute total segment duration in seconds from parsed fragment data. */
  private computeSegmentDuration(parseResult: MediaSegmentParseResult): number {
    let totalDurationTimescale = 0;
    let timescale = 1000;

    for (const fragment of parseResult.fragments) {
      const isVideo = this.videoTrack && fragment.trackId === this.videoTrack.trackId;
      timescale = isVideo ? this.videoTimescale : this.audioTimescale;

      for (const sample of fragment.samples) {
        totalDurationTimescale += sample.duration || fragment.defaultSampleDuration;
      }
    }

    return timescale > 0 ? totalDurationTimescale / timescale : 0;
  }

  /** Add a decoded time range, merging with adjacent ranges. */
  private addDecodedRange(start: number, end: number): void {
    // Simple approach: find overlapping or adjacent range and merge
    let merged = false;
    for (let i = 0; i < this.decodedRanges.length; i++) {
      const existing = this.decodedRanges[i]!;
      // Overlapping or adjacent
      if (start <= existing.end && end >= existing.start) {
        this.decodedRanges[i] = {
          start: Math.min(existing.start, start),
          end: Math.max(existing.end, end),
        };
        merged = true;
        break;
      }
    }
    if (!merged) {
      this.decodedRanges.push({ start, end });
    }
    // Keep sorted
    this.decodedRanges.sort((a, b) => a.start - b.start);
  }

  getStats(): BufferStats {
    // Estimate forward buffer from decoded ranges
    let forwardBuffer = 0;
    for (const range of this.decodedRanges) {
      // Since we don't have a playhead reference in WebCodecs mode,
      // we use lastDecodedTime as an approximation
      if (range.end > 0) {
        forwardBuffer = range.end - range.start;
      }
    }

    return {
      forwardBuffer,
      bufferedRanges: [...this.decodedRanges],
      segmentCount: this.segmentCount,
      bytesBuffered: this.totalBytesBuffered,
    };
  }

  hasSufficientBuffer(): boolean {
    return this.initialized && this.segmentCount > 0;
  }

  getBufferedRanges(): TimeRange[] {
    return [...this.decodedRanges];
  }

  async seekTo(_time: number): Promise<void> {
    // Flush decoders to drop queued frames
    try {
      if (this.videoDecoder?.state === 'configured') {
        this.videoDecoder.reset();
        // Re-configure after reset
        if (this.videoTrack?.description) {
          this.videoDecoder.configure({
            codec: this.videoCodecString,
            description: this.videoTrack.description,
            optimizeForLatency: true,
          });
        }
      }
      if (this.audioDecoder?.state === 'configured') {
        this.audioDecoder.reset();
        if (this.audioTrack?.description) {
          this.audioDecoder.configure({
            codec: this.audioCodecString,
            description: this.audioTrack.description,
            sampleRate: this.audioTimescale,
            numberOfChannels: 2,
          });
        }
      }
    } catch {
      // Decoder state may not allow reset — will be re-created if needed
    }

    // Clear tracked ranges
    this.decodedRanges = [];
    this.lastDecodedTime = _time;
    this.videoBaseTimestamp = -1;
    this.webCodecsAccumulatedTimeUs = 0;

    // Reset audio scheduling
    if (this.audioContext) {
      this.audioNextStartTime = this.audioContext.currentTime;
    }
  }

  async destroy(): Promise<void> {
    try {
      if (this.videoDecoder?.state !== 'closed') {
        this.videoDecoder?.reset();
        this.videoDecoder?.close();
      }
    } catch { /* ignore */ }
    try {
      if (this.audioDecoder?.state !== 'closed') {
        this.audioDecoder?.reset();
        this.audioDecoder?.close();
      }
    } catch { /* ignore */ }

    if (this.audioContext?.state !== 'closed') {
      await this.audioContext?.close().catch(() => {});
    }

    this.videoDecoder = null;
    this.audioDecoder = null;
    this.audioContext = null;
    this.canvas = null;
    this.initialized = false;
    this.decodersConfigured = false;
    this.videoTrack = null;
    this.audioTrack = null;
    this.decodedRanges = [];
    this.totalBytesBuffered = 0;
    this.segmentCount = 0;
    this.lastDecodedTime = 0;
  }
}

// ---------------------------------------------------------------------------
// HybridBufferEngine (Public API)
// ---------------------------------------------------------------------------

/**
 * HybridBufferEngine — automatically selects MSE or WebCodecs based on
 * browser capabilities, and provides a unified buffer management API.
 *
 * Usage:
 * ```ts
 * const buffer = new HybridBufferEngine(videoElement, config, events, { webcodecs: true });
 * const backend = buffer.getBackend(); // 'mse' or 'webcodecs'
 *
 * await buffer.init(rendition);
 * await buffer.appendInit(initData);
 * await buffer.appendSegment(0, segData);
 *
 * const stats = buffer.getStats();
 * ```
 */
export class HybridBufferEngine {
  private mseEngine: MSEBufferEngine | null = null;
  private webcodecsEngine: WebCodecsBufferEngine | null = null;
  private backend: BufferBackend = 'mse';
  private canvas: OffscreenCanvas | HTMLCanvasElement | null = null;

  constructor(
    private readonly videoElement: HTMLVideoElement,
    private readonly config: BufferEngineConfig,
    private readonly events: BufferEngineEvents,
    private readonly features: { webcodecs: boolean },
  ) {}

  /** Initialize the buffer engine with a rendition. */
  async init(rendition: Rendition): Promise<void> {
    // Try MSE first
    if (typeof MediaSource !== 'undefined') {
      this.mseEngine = new MSEBufferEngine(this.videoElement, this.config, this.events);
      try {
        await this.mseEngine.init(rendition);
        this.backend = 'mse';
        return;
      } catch {
        this.mseEngine = null;
      }
    }

    // Fallback to WebCodecs
    if (this.features.webcodecs && typeof VideoDecoder !== 'undefined') {
      this.canvas = this.createCanvas(rendition);
      this.webcodecsEngine = new WebCodecsBufferEngine(this.config, this.events);
      await this.webcodecsEngine.init(rendition, this.canvas);
      this.backend = 'webcodecs';
      return;
    }

    throw new Error('No supported buffer backend (MSE or WebCodecs)');
  }

  /** Append init segment. */
  async appendInit(data: ArrayBuffer): Promise<void> {
    if (this.backend === 'mse' && this.mseEngine) {
      return this.mseEngine.appendInit(data);
    }
    if (this.backend === 'webcodecs' && this.webcodecsEngine) {
      return this.webcodecsEngine.appendInit(data);
    }
    throw new Error('Buffer not initialized');
  }

  /** Append a media segment. */
  async appendSegment(segmentIndex: number, data: ArrayBuffer): Promise<void> {
    if (this.backend === 'mse' && this.mseEngine) {
      return this.mseEngine.appendSegment(segmentIndex, data);
    }
    if (this.backend === 'webcodecs' && this.webcodecsEngine) {
      return this.webcodecsEngine.appendSegment(segmentIndex, data);
    }
    throw new Error('Buffer not initialized');
  }

  /** Get buffer statistics. */
  getStats(): BufferStats {
    if (this.backend === 'mse' && this.mseEngine) {
      return this.mseEngine.getStats();
    }
    if (this.backend === 'webcodecs' && this.webcodecsEngine) {
      return this.webcodecsEngine.getStats();
    }
    return { forwardBuffer: 0, bufferedRanges: [], segmentCount: 0, bytesBuffered: 0 };
  }

  /** Check if there's enough buffer to start/resume playback. */
  hasSufficientBuffer(): boolean {
    if (this.backend === 'mse' && this.mseEngine) return this.mseEngine.hasSufficientBuffer();
    if (this.backend === 'webcodecs' && this.webcodecsEngine) return this.webcodecsEngine.hasSufficientBuffer();
    return false;
  }

  /** Handle a seek operation. */
  async seekTo(time: number): Promise<void> {
    if (this.backend === 'mse' && this.mseEngine) {
      return this.mseEngine.seekTo(time);
    }
    if (this.backend === 'webcodecs' && this.webcodecsEngine) {
      return this.webcodecsEngine.seekTo(time);
    }
  }

  /** Get buffered time ranges. */
  getBufferedRanges(): TimeRange[] {
    if (this.backend === 'mse' && this.mseEngine) {
      return this.mseEngine.getBufferedRanges();
    }
    if (this.backend === 'webcodecs' && this.webcodecsEngine) {
      return this.webcodecsEngine.getBufferedRanges();
    }
    return [];
  }

  /** Which backend is active. */
  getBackend(): BufferBackend {
    return this.backend;
  }

  /** Set canvas for WebCodecs rendering. */
  setCanvas(canvas: OffscreenCanvas | HTMLCanvasElement): void {
    this.canvas = canvas;
  }

  /** Destroy all resources. */
  async destroy(): Promise<void> {
    await this.mseEngine?.destroy();
    await this.webcodecsEngine?.destroy();
    this.mseEngine = null;
    this.webcodecsEngine = null;
  }

  /** Append audio init segment to the audio source buffer. */
  async appendAudioInit(data: ArrayBuffer): Promise<void> {
    if (this.backend === 'mse' && this.mseEngine) {
      return this.mseEngine.appendAudioInit(data);
    }
    // WebCodecs: audio init is handled inline during appendInit
  }

  /** Append an audio media segment to the audio source buffer. */
  async appendAudioSegment(data: ArrayBuffer): Promise<void> {
    if (this.backend === 'mse' && this.mseEngine) {
      return this.mseEngine.appendAudioSegment(data);
    }
    // WebCodecs: audio segments are handled inline during appendSegment
  }

  private createCanvas(rendition: Rendition): OffscreenCanvas {
    const width = this.config.webCodecsCanvasWidth ?? rendition.width;
    const height = this.config.webCodecsCanvasHeight ?? rendition.height;
    return new OffscreenCanvas(width, height);
  }
}
