/**
 * ISOBMFF / fMP4 binary construction helpers for tests.
 *
 * Build valid fMP4 init segments (ftyp+moov) and media segments (moof+mdat)
 * with correct box layouts for testing the HybridBufferEngine parsers.
 */

// ---------------------------------------------------------------------------
// Primitives
// ---------------------------------------------------------------------------

/** Build a raw ISOBMFF box: [4-byte size][4-byte type][payload] */
export function buildBox(type: string, content: Uint8Array = new Uint8Array(0)): Uint8Array {
  const size = 8 + content.length;
  const buf = new Uint8Array(size);
  const view = new DataView(buf.buffer);
  view.setUint32(0, size);
  for (let i = 0; i < 4; i++) buf[4 + i] = type.charCodeAt(i);
  if (content.length > 0) buf.set(content, 8);
  return buf;
}

/** Concatenate multiple Uint8Arrays into one. */
export function concat(...parts: Uint8Array[]): Uint8Array {
  const total = parts.reduce((s, p) => s + p.length, 0);
  const result = new Uint8Array(total);
  let offset = 0;
  for (const p of parts) {
    result.set(p, offset);
    offset += p.length;
  }
  return result;
}

/** Build a 4-byte big-endian uint32. */
export function u32(val: number): Uint8Array {
  const buf = new Uint8Array(4);
  new DataView(buf.buffer).setUint32(0, val);
  return buf;
}

/** Build a 2-byte big-endian uint16. */
export function u16(val: number): Uint8Array {
  const buf = new Uint8Array(2);
  new DataView(buf.buffer).setUint16(0, val);
  return buf;
}

/** Build a 1-byte uint8. */
export function u8(val: number): Uint8Array {
  return new Uint8Array([val]);
}

/** Build an 8-byte big-endian uint64 (as two u32s). */
export function u64(hi: number, lo: number): Uint8Array {
  return concat(u32(hi), u32(lo));
}

/** Build a 4-byte ASCII type string. */
export function fourcc(type: string): Uint8Array {
  const buf = new Uint8Array(4);
  for (let i = 0; i < 4; i++) buf[i] = type.charCodeAt(i);
  return buf;
}

/** Build a full box: [size][type][1-byte version][3-byte flags][payload] */
export function buildFullBox(type: string, version: number, flags: number, content: Uint8Array = new Uint8Array(0)): Uint8Array {
  const inner = concat(u8(version), u8((flags >> 16) & 0xFF), u8((flags >> 8) & 0xFF), u8(flags & 0xFF), content);
  return buildBox(type, inner);
}

// ---------------------------------------------------------------------------
// fMP4 init.mp4 boxes
// ---------------------------------------------------------------------------

/** Build a minimal mvhd (movie header) box. Version 0. */
export function buildMvhd(timescale: number, duration: number): Uint8Array {
  const content = concat(
    u32(0), u32(0), u32(timescale), u32(duration),
    u32(0x00010000), u16(0x0100), new Uint8Array(10),
    u32(0x00010000), u32(0), u32(0),
    u32(0), u32(0x00010000), u32(0),
    u32(0), u32(0), u32(0x40000000),
    new Uint8Array(24), u32(2),
  );
  return buildFullBox('mvhd', 0, 0, content);
}

/** Build a tkhd (track header) box. Version 0. */
export function buildTkhd(trackId: number): Uint8Array {
  const content = concat(
    u32(0), u32(0), u32(trackId), u32(0), u32(0),
    new Uint8Array(8), u16(0), u16(0), u16(0), u16(0),
    u32(0x00010000), u32(0), u32(0),
    u32(0), u32(0x00010000), u32(0),
    u32(0), u32(0), u32(0x40000000),
    u32(0x01200000), u32(0x00690000),
  );
  return buildFullBox('tkhd', 0, 1, content);
}

/** Build an hdlr (handler) box. */
export function buildHdlr(handlerType: string): Uint8Array {
  const content = concat(u32(0), fourcc(handlerType), new Uint8Array(12), new Uint8Array(1));
  return buildFullBox('hdlr', 0, 0, content);
}

/** Build an mdhd (media header) box. Version 0. */
export function buildMdhd(timescale: number): Uint8Array {
  const content = concat(u32(0), u32(0), u32(timescale), u32(0), u16(0x55C4), u16(0));
  return buildFullBox('mdhd', 0, 0, content);
}

/** Build a valid hvcC (HEVC decoder configuration record) box. */
export function buildHvcC(
  profileSpace: number,
  tier: number,
  profileIdc: number,
  profileCompatibility: number,
  constraintFlags: Uint8Array,
  level: number,
): Uint8Array {
  const profileByte = ((profileSpace & 0x03) << 6) | ((tier & 0x01) << 5) | (profileIdc & 0x1F);
  const content = concat(
    u8(1), u8(profileByte), u32(profileCompatibility),
    constraintFlags.length === 4 ? constraintFlags : new Uint8Array(4),
    u8(level), u16(0xF000), u8(0xFC), u8(0xFC), u8(0xF8), u8(0xF8),
    u16(0), u8(0x0F), u8(0),
  );
  return buildBox('hvcC', content);
}

/** Build a valid avcC (AVC decoder configuration record) box. */
export function buildAvcC(profile: number, compat: number, level: number): Uint8Array {
  const content = concat(
    u8(1), u8(profile), u8(compat), u8(level),
    u8(0xFF), u8(0xE1), u16(4),
    new Uint8Array([0x67, 0x64, 0x00, 0x1F]),
    u8(1), u16(4),
    new Uint8Array([0x68, 0xEE, 0x3C, 0x80]),
  );
  return buildBox('avcC', content);
}

/** Build a video sample entry (hvc1 or avc1) with the given codec config box inside. */
export function buildVideoSampleEntry(entryType: string, codecConfigBox: Uint8Array): Uint8Array {
  const content = concat(
    new Uint8Array(6), u16(1), u16(0), u16(0), new Uint8Array(12),
    u16(1920), u16(1080), u32(0x00480000), u32(0x00480000), u32(0),
    u16(1), new Uint8Array(32), u16(0x0018), u16(0xFFFF),
    codecConfigBox,
  );
  return buildBox(entryType, content);
}

/** Build an audio sample entry (mp4a) with an esds box inside. */
export function buildAudioSampleEntry(): Uint8Array {
  const esdsContent = concat(u8(0), u8(0), u8(1), u8(0));
  const esds = buildFullBox('esds', 0, 0, esdsContent);
  const content = concat(
    new Uint8Array(6), u16(1), new Uint8Array(8), u16(2), u16(16),
    u16(0), u16(0), u32(44100 << 16), esds,
  );
  return buildBox('mp4a', content);
}

/** Build an stsd (sample description) box. */
export function buildStsd(entries: Uint8Array[]): Uint8Array {
  const content = concat(u32(entries.length), ...entries);
  return buildFullBox('stsd', 0, 0, content);
}

/** Build a complete video trak box. */
export function buildVideoTrak(trackId: number, codecConfigBox: Uint8Array, entryType: string = 'hvc1'): Uint8Array {
  const stsd = buildStsd([buildVideoSampleEntry(entryType, codecConfigBox)]);
  const stbl = buildBox('stbl', stsd);
  const minf = buildBox('minf', stbl);
  const mdhd = buildMdhd(24000);
  const hdlr = buildHdlr('vide');
  const mdia = buildBox('mdia', concat(mdhd, hdlr, minf));
  const tkhd = buildTkhd(trackId);
  return buildBox('trak', concat(tkhd, mdia));
}

/** Build a complete audio trak box. */
export function buildAudioTrak(trackId: number): Uint8Array {
  const stsd = buildStsd([buildAudioSampleEntry()]);
  const stbl = buildBox('stbl', stsd);
  const minf = buildBox('minf', stbl);
  const mdhd = buildMdhd(44100);
  const hdlr = buildHdlr('soun');
  const mdia = buildBox('mdia', concat(mdhd, hdlr, minf));
  const tkhd = buildTkhd(trackId);
  return buildBox('trak', concat(tkhd, mdia));
}

/** Build a complete init.mp4 (ftyp + moov). */
export function buildInitMp4(traks: Uint8Array[], timescale = 1000, duration = 0): ArrayBuffer {
  const ftyp = buildBox('ftyp', concat(fourcc('isom'), u32(0x200), fourcc('isom'), fourcc('iso2'), fourcc('mp41')));
  const mvhd = buildMvhd(timescale, duration);
  const moov = buildBox('moov', concat(mvhd, ...traks));
  return concat(ftyp, moov).buffer;
}

// ---------------------------------------------------------------------------
// fMP4 media segment (moof+mdat) boxes
// ---------------------------------------------------------------------------

/** Build a tfhd (track fragment header) box. */
export function buildTfhd(
  trackId: number,
  flags: number = 0,
  baseDataOffset?: number,
  defaultSampleDuration?: number,
  defaultSampleSize?: number,
): Uint8Array {
  const parts: Uint8Array[] = [u32(trackId)];
  if (flags & 0x000001) parts.push(u64(0, baseDataOffset ?? 0));
  if (flags & 0x000008) parts.push(u32(defaultSampleDuration ?? 0));
  if (flags & 0x000010) parts.push(u32(defaultSampleSize ?? 0));
  return buildFullBox('tfhd', 0, flags, concat(...parts));
}

/** Build a trun (track fragment run) box. */
export function buildTrun(
  samples: Array<{ duration?: number; size?: number; sampleFlags?: number; ctsOffset?: number }>,
  dataOffset?: number,
  trunFlags: number = 0x000001 | 0x000100 | 0x000200,
): Uint8Array {
  const parts: Uint8Array[] = [u32(samples.length)];
  if (trunFlags & 0x000001) parts.push(u32(dataOffset ?? 0));
  for (const s of samples) {
    if (trunFlags & 0x000100) parts.push(u32(s.duration ?? 0));
    if (trunFlags & 0x000200) parts.push(u32(s.size ?? 0));
    if (trunFlags & 0x000400) parts.push(u32(s.sampleFlags ?? 0));
    if (trunFlags & 0x000800) parts.push(u32(s.ctsOffset ?? 0));
  }
  const header = concat(u8(0), u8((trunFlags >> 16) & 0xFF), u8((trunFlags >> 8) & 0xFF), u8(trunFlags & 0xFF));
  return buildBox('trun', concat(header, ...parts));
}

/** Build a moof box from tfhd + trun. */
export function buildMoof(trackId: number, samples: Array<{ duration?: number; size?: number; sampleFlags?: number; ctsOffset?: number }>, dataOffset: number): Uint8Array {
  const tfhd = buildTfhd(trackId);
  const trun = buildTrun(samples, dataOffset, 0x000001 | 0x000100 | 0x000200);
  return buildBox('moof', concat(tfhd, trun));
}

/** Build an mdat box with the given payload. */
export function buildMdat(payload: Uint8Array): Uint8Array {
  return buildBox('mdat', payload);
}
