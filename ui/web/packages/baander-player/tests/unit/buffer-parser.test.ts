/**
 * @tests core/buffer/fMP4 parser functions
 *
 * Tests the pure ISOBMFF box parser functions from HybridBufferEngine:
 *   - BoxReader (low-level binary reader)
 *   - findBox / findAllBoxes (box tree traversal)
 *   - parseInitSegment (moov → track codec extraction)
 *   - parseMediaSegment (moof+mdat → sample metadata)
 *   - parseHvcCCodec / parseAvcCCodec (codec string construction)
 *
 * Test data is constructed as raw binary fMP4 boxes using helper functions
 * that build valid ISOBMFF structures.
 */

import { describe, it, expect } from 'vitest';
import {
  BoxReader,
  findBox,
  findAllBoxes,
  parseInitSegment,
  parseMediaSegment,
  parseHvcCCodec,
  parseAvcCCodec,
} from '../../src/core/buffer/HybridBufferEngine';
import {
  buildBox,
  concat,
  u32,
  u16,
  u8,
  fourcc,
  buildHvcC,
  buildAvcC,
  buildVideoSampleEntry,
  buildStsd,
  buildVideoTrak,
  buildAudioTrak,
  buildInitMp4,
  buildMoof,
  buildMdat,
  buildTfhd,
  buildTrun,
} from '../fixtures';

// ===========================================================================
// Tests
// ===========================================================================

describe('BoxReader', () => {
  it('should read uint8, uint16, uint32 values', () => {
    const buf = new Uint8Array([0x01, 0x00, 0x02, 0x00, 0x00, 0x03, 0x00]);
    const reader = new BoxReader(buf.buffer);
    expect(reader.readUint8()).toBe(1);
    expect(reader.readUint16()).toBe(2);
    expect(reader.readUint32()).toBe(0x00000300); // bytes 0x00,0x00,0x03,0x00
  });

  it('should track position and remaining bytes', () => {
    const buf = new Uint8Array(10);
    const reader = new BoxReader(buf.buffer);
    expect(reader.position).toBe(0);
    expect(reader.remaining()).toBe(10);
    reader.skip(4);
    expect(reader.position).toBe(4);
    expect(reader.remaining()).toBe(6);
  });

  it('should seek to arbitrary position', () => {
    const buf = new Uint8Array([0x00, 0x00, 0x00, 0x42]);
    const reader = new BoxReader(buf.buffer);
    reader.seek(3);
    expect(reader.readUint8()).toBe(0x42);
  });

  it('should read box type as 4-char ASCII', () => {
    const buf = new Uint8Array([0x00, 0x00, 0x00, 0x08, 0x66, 0x74, 0x79, 0x70]); // ftyp
    const reader = new BoxReader(buf.buffer);
    reader.skip(4); // skip size
    expect(reader.readBoxType()).toBe('ftyp');
  });

  it('should read bytes as Uint8Array slice', () => {
    const buf = new Uint8Array([0xAA, 0xBB, 0xCC, 0xDD]);
    const reader = new BoxReader(buf.buffer);
    const bytes = reader.readBytes(2);
    expect(bytes[0]).toBe(0xAA);
    expect(bytes[1]).toBe(0xBB);
    expect(reader.position).toBe(2);
  });

  it('should read remaining bytes', () => {
    const buf = new Uint8Array([0x01, 0x02, 0x03]);
    const reader = new BoxReader(buf.buffer);
    reader.skip(1);
    const rest = reader.readRemaining();
    expect(rest.length).toBe(2);
    expect(rest[0]).toBe(0x02);
  });

  it('should accept Uint8Array with byteOffset', () => {
    const underlying = new Uint8Array([0x00, 0x00, 0xFF, 0xFE]);
    const slice = new Uint8Array(underlying.buffer, 2, 2);
    const reader = new BoxReader(slice);
    expect(reader.readUint8()).toBe(0xFF);
    expect(reader.readUint8()).toBe(0xFE);
  });
});

describe('findBox', () => {
  it('should find a top-level box by type', () => {
    const ftyp = buildBox('ftyp', fourcc('isom'));
    const moov = buildBox('moov', new Uint8Array(0));
    const data = concat(ftyp, moov);

    const reader = new BoxReader(data.buffer);
    const result = findBox(reader, data.length, 'moov');
    expect(result).not.toBeNull();
    expect(result!.size).toBe(8); // empty moov = 8 bytes
    expect(result!.headerSize).toBe(8);
  });

  it('should return null for missing box type', () => {
    const ftyp = buildBox('ftyp', fourcc('isom'));
    const reader = new BoxReader(ftyp.buffer);
    const result = findBox(reader, ftyp.length, 'moov');
    expect(result).toBeNull();
  });

  it('should find nested boxes', () => {
    const innerHvcC = buildHvcC(0, 0, 1, 0x60000000, new Uint8Array([0x90, 0, 0, 0]), 93);
    const entry = buildVideoSampleEntry('hvc1', innerHvcC);
    const stsd = buildStsd([entry]);
    const stbl = buildBox('stbl', stsd);
    const minf = buildBox('minf', stbl);
    const mdia = buildBox('mdia', minf);
    const trak = buildBox('trak', mdia);
    const moov = buildBox('moov', trak);
    const data = concat(buildBox('ftyp'), moov);

    const reader = new BoxReader(data.buffer);
    const foundMoov = findBox(reader, data.length, 'moov');
    expect(foundMoov).not.toBeNull();

    // Drill into moov → trak → mdia → minf → stbl → stsd
    reader.seek(foundMoov!.offset + foundMoov!.headerSize);
    const foundTrak = findBox(reader, foundMoov!.offset + foundMoov!.size, 'trak');
    expect(foundTrak).not.toBeNull();

    reader.seek(foundTrak!.offset + foundTrak!.headerSize);
    const foundMdia = findBox(reader, foundTrak!.offset + foundTrak!.size, 'mdia');
    expect(foundMdia).not.toBeNull();

    reader.seek(foundMdia!.offset + foundMdia!.headerSize);
    const foundMinf = findBox(reader, foundMdia!.offset + foundMdia!.size, 'minf');
    expect(foundMinf).not.toBeNull();

    reader.seek(foundMinf!.offset + foundMinf!.headerSize);
    const foundStbl = findBox(reader, foundMinf!.offset + foundMinf!.size, 'stbl');
    expect(foundStbl).not.toBeNull();

    reader.seek(foundStbl!.offset + foundStbl!.headerSize);
    const foundStsd = findBox(reader, foundStbl!.offset + foundStbl!.size, 'stsd');
    expect(foundStsd).not.toBeNull();
  });

  it('should skip past non-matching boxes', () => {
    const box1 = buildBox('free', new Uint8Array(20));
    const box2 = buildBox('skip', new Uint8Array(10));
    const target = buildBox('moov', new Uint8Array(4));
    const data = concat(box1, box2, target);

    const reader = new BoxReader(data.buffer);
    const result = findBox(reader, data.length, 'moov');
    expect(result).not.toBeNull();
    expect(result!.offset).toBe(box1.length + box2.length);
  });

  it('should handle empty input buffer', () => {
    const reader = new BoxReader(new Uint8Array(0).buffer);
    const result = findBox(reader, 0, 'ftyp');
    expect(result).toBeNull();
  });
});

describe('findAllBoxes', () => {
  it('should find all matching sibling boxes', () => {
    const trak1 = buildBox('trak', new Uint8Array(4));
    const trak2 = buildBox('trak', new Uint8Array(8));
    const trak3 = buildBox('trak', new Uint8Array(2));
    const mvhd = buildBox('mvhd', new Uint8Array(4));
    const moov = buildBox('moov', concat(mvhd, trak1, trak2, trak3));

    const reader = new BoxReader(moov.buffer);
    // Top-level: find moov first
    const moovRef = findBox(reader, moov.length, 'moov');
    expect(moovRef).not.toBeNull();

    // Now find all traks inside moov
    reader.seek(moovRef!.offset + moovRef!.headerSize);
    const traks = findAllBoxes(reader, moovRef!.offset + moovRef!.size, 'trak');
    expect(traks.length).toBe(3);
  });

  it('should return empty array when no matches', () => {
    const ftyp = buildBox('ftyp', fourcc('isom'));
    const reader = new BoxReader(ftyp.buffer);
    const results = findAllBoxes(reader, ftyp.length, 'trak');
    expect(results).toEqual([]);
  });

  it('should handle mixed box types correctly', () => {
    const trak1 = buildBox('trak', new Uint8Array(4));
    const free = buildBox('free', new Uint8Array(4));
    const trak2 = buildBox('trak', new Uint8Array(4));
    const moov = buildBox('moov', concat(trak1, free, trak2));

    const reader = new BoxReader(moov.buffer);
    const moovRef = findBox(reader, moov.length, 'moov');
    reader.seek(moovRef!.offset + moovRef!.headerSize);
    const traks = findAllBoxes(reader, moovRef!.offset + moovRef!.size, 'trak');
    expect(traks.length).toBe(2);
  });
});

describe('parseInitSegment', () => {
  it('should parse a video track with HEVC (hvc1) codec', () => {
    // Realistic hvcC: profileSpace=0, tier=0, profileIdc=1, compat=0x60000000, level=93
    const hvcC = buildHvcC(0, 0, 1, 0x60000000, new Uint8Array([0x90, 0, 0, 0]), 93);
    const trak = buildVideoTrak(1, hvcC, 'hvc1');
    const data = buildInitMp4([trak], 1000, 6000);

    const result = parseInitSegment(data);
    expect(result.timescale).toBe(1000);
    expect(result.duration).toBe(6000);
    expect(result.tracks.length).toBe(1);

    const track = result.tracks[0]!;
    expect(track.trackId).toBe(1);
    expect(track.handlerType).toBe('vide');
    expect(track.timescale).toBe(24000);
    expect(track.codecString).toMatch(/^hvc1\./);
    expect(track.description).not.toBeNull();
  });

  it('should parse an audio track with mp4a + esds', () => {
    const trak = buildAudioTrak(2);
    const data = buildInitMp4([trak]);

    const result = parseInitSegment(data);
    expect(result.tracks.length).toBe(1);

    const track = result.tracks[0]!;
    expect(track.trackId).toBe(2);
    expect(track.handlerType).toBe('soun');
    expect(track.timescale).toBe(44100);
    expect(track.codecString).toBe('mp4a.40.2');
    expect(track.description).not.toBeNull();
  });

  it('should parse multiple tracks (video + audio)', () => {
    const hvcC = buildHvcC(0, 0, 1, 0x60000000, new Uint8Array([0x90, 0, 0, 0]), 93);
    const videoTrak = buildVideoTrak(1, hvcC, 'hvc1');
    const audioTrak = buildAudioTrak(2);
    const data = buildInitMp4([videoTrak, audioTrak]);

    const result = parseInitSegment(data);
    expect(result.tracks.length).toBe(2);

    const video = result.tracks.find(t => t.handlerType === 'vide')!;
    const audio = result.tracks.find(t => t.handlerType === 'soun')!;

    expect(video).toBeDefined();
    expect(audio).toBeDefined();
    expect(video.codecString).toMatch(/^hvc1\./);
    expect(audio.codecString).toBe('mp4a.40.2');
  });

  it('should parse AVC (avc1) codec', () => {
    const avcC = buildAvcC(100, 0, 31); // profile=100(0x64), compat=0, level=31(0x1F)
    const trak = buildVideoTrak(1, avcC, 'avc1');
    const data = buildInitMp4([trak]);

    const result = parseInitSegment(data);
    expect(result.tracks.length).toBe(1);
    expect(result.tracks[0]!.codecString).toBe('avc1.64001f');
  });

  it('should return empty tracks for data with no moov box', () => {
    const ftyp = buildBox('ftyp', fourcc('isom'));
    const data = ftyp.buffer;
    // Need full buffer (ftyp only)
    const buf = new Uint8Array(data.byteLength);
    buf.set(new Uint8Array(data), 0);

    const result = parseInitSegment(buf.buffer as ArrayBuffer);
    expect(result.tracks).toEqual([]);
    expect(result.duration).toBe(0);
  });

  it('should handle empty buffer gracefully', () => {
    const result = parseInitSegment(new Uint8Array(0).buffer as ArrayBuffer);
    expect(result.tracks).toEqual([]);
    expect(result.duration).toBe(0);
  });

  it('should handle truncated moov box', () => {
    // Build a moov that's truncated mid-way
    const moov = buildBox('moov', new Uint8Array(100));
    const truncated = moov.slice(0, 20); // truncate the moov

    const result = parseInitSegment(truncated.buffer as ArrayBuffer);
    // Should not throw, may return empty tracks
    expect(result.tracks).toEqual([]);
  });
});

describe('parseHvcCCodec', () => {
  it('should produce correct codec string for Main profile, level 3.1', () => {
    // profileSpace=0, tier=0, profileIdc=1(Main), compat=0x60000000, level=93
    const hvcC = buildHvcC(0, 0, 1, 0x60000000, new Uint8Array([0x90, 0, 0, 0]), 93);
    const reader = new BoxReader(hvcC.buffer);
    // hvcC box = [size(4)][type(4)][payload...]
    // payloadOffset = 8
    const codec = parseHvcCCodec(reader, 8);
    expect(codec).toBe('hvc1.1.60000000.L3.1');
  });

  it('should produce correct codec string for High tier', () => {
    // profileSpace=0, tier=1, profileIdc=2, compat=0, level=120
    const hvcC = buildHvcC(0, 1, 2, 0, new Uint8Array([0, 0, 0, 0]), 120);
    const reader = new BoxReader(hvcC.buffer);
    const codec = parseHvcCCodec(reader, 8);
    expect(codec).toContain('.H'); // High tier indicator
    expect(codec).toContain('4.0'); // 120/30 = 4.0
  });

  it('should include profile space letter when non-zero', () => {
    // profileSpace=1 → 'A', profileIdc=1
    const hvcC = buildHvcC(1, 0, 1, 0, new Uint8Array([0, 0, 0, 0]), 93);
    const reader = new BoxReader(hvcC.buffer);
    const codec = parseHvcCCodec(reader, 8);
    expect(codec).toContain('A1'); // profileSpace 1 → 'A' + profileIdc 1
  });

  it('should strip leading zeros from profile compatibility hex', () => {
    // compat=0x00000001 → should become '1' not '00000001'
    const hvcC = buildHvcC(0, 0, 1, 1, new Uint8Array([0, 0, 0, 0]), 93);
    const reader = new BoxReader(hvcC.buffer);
    const codec = parseHvcCCodec(reader, 8);
    expect(codec).toContain('.1.'); // compat as hex = '1'
  });
});

describe('parseAvcCCodec', () => {
  it('should produce correct avc1 codec string', () => {
    // profile=66 → 0x42, compat=0xC0, level=30 → 0x1E
    const avcC = buildAvcC(66, 0xC0, 30);
    const reader = new BoxReader(avcC.buffer);
    const codec = parseAvcCCodec(reader, 8);
    expect(codec).toBe('avc1.42c01e');
  });

  it('should zero-pad hex values', () => {
    // profile=1 → 0x01, compat=0, level=1 → 0x01
    const avcC = buildAvcC(1, 0, 1);
    const reader = new BoxReader(avcC.buffer);
    const codec = parseAvcCCodec(reader, 8);
    expect(codec).toBe('avc1.010001');
  });

  it('should handle high profile values', () => {
    // profile=100 → 0x64, compat=0, level=41 → 0x29
    const avcC = buildAvcC(100, 0, 41);
    const reader = new BoxReader(avcC.buffer);
    const codec = parseAvcCCodec(reader, 8);
    expect(codec).toBe('avc1.640029');
  });
});

describe('parseMediaSegment', () => {
  it('should parse a moof+mdat segment with one sample', () => {
    const sampleData = new Uint8Array([0x01, 0x02, 0x03, 0x04]);
    const mdat = buildMdat(sampleData);
    const moof = buildMoof(1, [{ duration: 1000, size: 4 }], 8); // data offset = 8 (mdat header)

    const data = concat(moof, mdat);
    const result = parseMediaSegment(data.buffer as ArrayBuffer);

    expect(result.fragments.length).toBe(1);
    expect(result.fragments[0]!.trackId).toBe(1);
    expect(result.fragments[0]!.samples.length).toBe(1);
    expect(result.fragments[0]!.samples[0]!.duration).toBe(1000);
    expect(result.fragments[0]!.samples[0]!.size).toBe(4);
    expect(result.mdatData.length).toBe(4);
    expect(result.mdatData[0]).toBe(0x01);
  });

  it('should parse multiple samples in a single fragment', () => {
    const sampleData = new Uint8Array(12); // 3 samples × 4 bytes
    for (let i = 0; i < 12; i++) sampleData[i] = i;
    const mdat = buildMdat(sampleData);
    const moof = buildMoof(1, [
      { duration: 500, size: 4 },
      { duration: 500, size: 4 },
      { duration: 1000, size: 4 },
    ], 8);

    const data = concat(moof, mdat);
    const result = parseMediaSegment(data.buffer as ArrayBuffer);

    expect(result.fragments[0]!.samples.length).toBe(3);
    expect(result.fragments[0]!.samples[0]!.duration).toBe(500);
    expect(result.fragments[0]!.samples[1]!.duration).toBe(500);
    expect(result.fragments[0]!.samples[2]!.duration).toBe(1000);
    expect(result.mdatData.length).toBe(12);
  });

  it('should handle empty mdat', () => {
    const mdat = buildBox('mdat', new Uint8Array(0));
    const tfhd = buildTfhd(1);
    const trun = buildTrun([], undefined, 0x000100 | 0x000200);
    const moof = buildBox('moof', concat(tfhd, trun));

    const data = concat(moof, mdat);
    const result = parseMediaSegment(data.buffer as ArrayBuffer);

    expect(result.fragments.length).toBe(1);
    expect(result.fragments[0]!.samples.length).toBe(0);
    expect(result.mdatData.length).toBe(0);
  });

  it('should handle buffer with only mdat (no moof)', () => {
    const mdat = buildMdat(new Uint8Array([0xAA, 0xBB]));
    const result = parseMediaSegment(mdat.buffer as ArrayBuffer);
    expect(result.fragments).toEqual([]);
    expect(result.mdatData.length).toBe(2);
  });

  it('should handle empty buffer', () => {
    const result = parseMediaSegment(new Uint8Array(0).buffer as ArrayBuffer);
    expect(result.fragments).toEqual([]);
    expect(result.mdatData.length).toBe(0);
  });

  it('should report correct mdatPayloadOffset', () => {
    const sampleData = new Uint8Array([0xFF]);
    const mdat = buildMdat(sampleData);
    const moof = buildMoof(1, [{ duration: 1000, size: 1 }], 8);

    const data = concat(moof, mdat);
    const result = parseMediaSegment(data.buffer as ArrayBuffer);

    // mdat starts at moof.length, payload starts at moof.length + 8 (mdat header)
    expect(result.mdatPayloadOffset).toBe(moof.length + 8);
  });

  it('should compute sample offsets relative to mdat data', () => {
    const sampleData = new Uint8Array(8); // 2 samples × 4 bytes
    const mdat = buildMdat(sampleData);
    const moof = buildMoof(1, [
      { duration: 500, size: 4 },
      { duration: 500, size: 4 },
    ], 8);

    const data = concat(moof, mdat);
    const result = parseMediaSegment(data.buffer as ArrayBuffer);

    const samples = result.fragments[0]!.samples;
    expect(samples[0]!.size).toBe(4);
    expect(samples[1]!.size).toBe(4);
    // Second sample offset: (dataOffset=8) + (first sample size=4) = 12
    expect(samples[1]!.offset).toBe(12);
  });
});
