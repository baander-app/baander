import { handleTrusted } from '../guard';
import { IPC_CHANNELS } from '../channels';
import { getAacService } from '../../main/services/aac.service';
import type {
  AacChannelMode,
  AacConcealment,
  AacDecoderOptions,
  AacDrcEffect,
  AacDrcProfile,
  AacEncoderOptions,
  AacProfile,
  AacRateControlMode,
  AacSignaling,
  AacTransport,
} from '@baander/aac-native';

const SAMPLE_RATES = new Set([8000, 11025, 12000, 16000, 22050, 24000, 32000, 44100, 48000, 64000, 88200, 96000]);
const MODES = new Set<AacRateControlMode>(['cbr', 'abr', 'cvbr', 'tvbr']);
const PROFILES = new Set<AacProfile>(['lc', 'he', 'hev2', 'ld', 'eld']);
const LOW_DELAY_PROFILES = new Set<AacProfile>(['ld', 'eld']);
const DRC_PROFILES = new Set<AacDrcProfile>(['none', 'film-standard', 'film-light', 'music-standard', 'music-light', 'speech']);
const DRC_EFFECTS = new Set<AacDrcEffect>(['off', 'none', 'night', 'noisy', 'limited', 'lowlevel', 'dialog', 'general']);
const MIN_TARGET_LUFS = -31;
const MAX_TARGET_LUFS = -10;
const MAX_CHANNELS = 8;
const TRANSPORTS = new Set<AacTransport>(['detect', 'adts', 'loas', 'latm', 'latm-raw', 'raw']);
const SIGNALINGS = new Set<AacSignaling>(['implicit', 'explicit', 'hierarchical']);
const CONCEALMENTS = new Set<AacConcealment>(['off', 'noise', 'interpolate']);
const CHANNEL_MODES = new Set<AacChannelMode>(['default', '7.1', '7.1-front', '7.1-top', '7.1-rear']);
const FRAME_LENGTHS = new Set([960, 1024]);
const LOW_DELAY_FRAME_LENGTHS = new Set([512, 480]);
const ELD_DOWNSCALES = new Set([1, 2, 4]);
const MAX_CONFIG_BYTES = 64;

function oneOf<T extends string>(value: unknown, allowed: Set<T>, what: string): T | undefined {
  if (value === undefined) return undefined;
  if (typeof value !== 'string' || !allowed.has(value as T)) invalid(what);
  return value as T;
}

function frameLength(value: unknown, lowDelay = false): 960 | 1024 | 512 | 480 | undefined {
  if (value === undefined) return undefined;
  const allowed = lowDelay ? LOW_DELAY_FRAME_LENGTHS : FRAME_LENGTHS;
  if (typeof value !== 'number' || !allowed.has(value)) invalid('frameLength');
  return value as 960 | 1024 | 512 | 480;
}

function percent(value: unknown, what: string): number | undefined {
  if (value === undefined) return undefined;
  if (!(typeof value === 'number' && Number.isInteger(value) && value >= 0 && value <= 100)) invalid(what);
  return value;
}

/** A reference level in quarter dB below full scale, 0..127; `none` admits -1. */
function refLevel(value: unknown, what: string, none: boolean): number | undefined {
  if (value === undefined) return undefined;
  const min = none ? -1 : 0;
  if (!(typeof value === 'number' && Number.isInteger(value) && value >= min && value <= 127)) invalid(what);
  return value;
}

function drcOption(value: unknown): AacDecoderOptions['drc'] {
  if (value === undefined || typeof value === 'boolean') return value;
  if (typeof value !== 'object' || value === null || Array.isArray(value)) invalid('drc');
  const { boost, cut, targetRefLevel, heavy } = value as Record<string, unknown>;
  return {
    boost: percent(boost, 'boost'),
    cut: percent(cut, 'cut'),
    targetRefLevel: refLevel(targetRefLevel, 'targetRefLevel', true),
    heavy: flag(heavy, 'heavy'),
  };
}

/** USAC loudness normalisation: a flag, or { enabled, targetLufs } with the target in LUFS. */
function loudnessOption(value: unknown): AacDecoderOptions['loudness'] {
  if (value === undefined || typeof value === 'boolean') return value;
  if (typeof value !== 'object' || value === null || Array.isArray(value)) invalid('loudness');
  const { enabled, targetLufs } = value as Record<string, unknown>;
  if (targetLufs !== undefined && !(typeof targetLufs === 'number' && Number.isFinite(targetLufs)
    && targetLufs >= MIN_TARGET_LUFS && targetLufs <= MAX_TARGET_LUFS)) invalid('targetLufs');
  return { enabled: flag(enabled, 'enabled'), targetLufs: targetLufs as number | undefined };
}

function eldDownscale(value: unknown): 1 | 2 | 4 | undefined {
  if (value === undefined) return undefined;
  if (typeof value !== 'number' || !ELD_DOWNSCALES.has(value)) invalid('eldDownscale');
  return value as 1 | 2 | 4;
}

function ldSbrOption(value: unknown): AacEncoderOptions['ldSbr'] {
  if (value === undefined || typeof value === 'boolean') return value;
  if (typeof value !== 'object' || value === null || Array.isArray(value)) invalid('ldSbr');
  return { dualRate: flag((value as Record<string, unknown>).dualRate, 'dualRate') };
}

function metadataOption(value: unknown): AacEncoderOptions['metadata'] {
  if (value === undefined) return undefined;
  if (typeof value !== 'object' || value === null || Array.isArray(value)) invalid('metadata');
  const { drcProfile, programRefLevel } = value as Record<string, unknown>;
  return {
    drcProfile: oneOf(drcProfile, DRC_PROFILES, 'drcProfile'),
    programRefLevel: refLevel(programRefLevel, 'programRefLevel', false),
  };
}

function flag(value: unknown, what: string): boolean | undefined {
  if (value === undefined) return undefined;
  if (typeof value !== 'boolean') invalid(what);
  return value;
}

function limiterOption(value: unknown): AacDecoderOptions['limiter'] {
  if (value === undefined || typeof value === 'boolean') return value;
  if (typeof value !== 'object' || value === null || Array.isArray(value)) invalid('limiter');
  const { attackMs, releaseMs } = value as Record<string, unknown>;
  for (const [name, ms] of [['attackMs', attackMs], ['releaseMs', releaseMs]] as const) {
    if (ms !== undefined && !(typeof ms === 'number' && Number.isInteger(ms) && ms >= 1 && ms <= 1000)) invalid(name);
  }
  return { attackMs: attackMs as number | undefined, releaseMs: releaseMs as number | undefined };
}

function channelCount(value: unknown, min: number): boolean {
  return typeof value === 'number' && Number.isInteger(value) && value >= min && value <= MAX_CHANNELS;
}

function invalid(message: string): never {
  throw new Error(`Invalid AAC request: ${message}`);
}

function bytes(value: unknown, what: string): Uint8Array {
  if (value instanceof Uint8Array) return value;
  if (value instanceof ArrayBuffer) return new Uint8Array(value);
  return invalid(`${what} must be a Uint8Array`);
}

function samples(value: unknown, what: string): Float32Array {
  if (value instanceof Float32Array) return value;
  return invalid(`${what} must be a Float32Array`);
}

function sessionId(value: unknown): number {
  if (typeof value !== 'number' || !Number.isInteger(value) || value <= 0) invalid('session id');
  return value;
}

function decoderOptions(value: unknown): AacDecoderOptions {
  const options = (value ?? {}) as Record<string, unknown>;
  if (typeof options !== 'object' || Array.isArray(options)) invalid('decoder options');
  const { sampleRate, channels, transport, config, concealment, limiter } = options;
  if (sampleRate !== undefined && !(typeof sampleRate === 'number' && SAMPLE_RATES.has(sampleRate))) invalid('sampleRate');
  if (channels !== undefined && !channelCount(channels, 0)) invalid('channels');
  if (config !== undefined && !(config instanceof Uint8Array && config.length <= MAX_CONFIG_BYTES)) invalid('config');
  return {
    sampleRate: sampleRate as number | undefined,
    channels: channels as number | undefined,
    transport: oneOf(transport, TRANSPORTS, 'transport'),
    config: config as Uint8Array | undefined,
    frameLength: frameLength(options.frameLength),
    concealment: oneOf(concealment, CONCEALMENTS, 'concealment'),
    limiter: limiterOption(limiter),
    drc: drcOption(options.drc),
    eldDownscale: eldDownscale(options.eldDownscale),
    loudness: loudnessOption(options.loudness),
    drcEffect: oneOf(options.drcEffect, DRC_EFFECTS, 'drcEffect'),
    albumMode: flag(options.albumMode, 'albumMode'),
    drcBoost: percent(options.drcBoost, 'drcBoost'),
    drcCut: percent(options.drcCut, 'drcCut'),
  };
}

function encoderOptions(value: unknown): AacEncoderOptions {
  const options = (value ?? {}) as Record<string, unknown>;
  if (typeof options !== 'object' || Array.isArray(options)) invalid('encoder options');
  const { sampleRate, channels, bitrate, mode, quality, profile, transport, signaling, channelMode } = options;
  if (transport === 'detect') invalid('transport');
  if (sampleRate !== undefined && !(typeof sampleRate === 'number' && SAMPLE_RATES.has(sampleRate))) invalid('sampleRate');
  if (channels !== undefined && !channelCount(channels, 1)) invalid('channels');
  if (profile !== undefined && !(typeof profile === 'string' && PROFILES.has(profile as AacProfile))) invalid('profile');
  if (bitrate !== undefined && !(typeof bitrate === 'number' && Number.isInteger(bitrate) && bitrate >= 8000 && bitrate <= 1_000_000)) invalid('bitrate');
  if (mode !== undefined && !(typeof mode === 'string' && MODES.has(mode as AacRateControlMode))) invalid('mode');
  if (quality !== undefined && !(typeof quality === 'number' && Number.isInteger(quality) && quality >= 1 && quality <= 100)) invalid('quality');
  const lowDelay = LOW_DELAY_PROFILES.has(profile as AacProfile);
  if (lowDelay && transport === 'adts') invalid('transport');
  if (options.ldSbr !== undefined && profile !== 'eld') invalid('ldSbr');
  return {
    sampleRate: sampleRate as number | undefined,
    channels: channels as number | undefined,
    bitrate: bitrate as number | undefined,
    mode: mode as AacRateControlMode | undefined,
    quality: quality as number | undefined,
    profile: profile as AacProfile | undefined,
    transport: oneOf(transport, TRANSPORTS, 'transport') as Exclude<AacTransport, 'detect'> | undefined,
    signaling: oneOf(signaling, SIGNALINGS, 'signaling'),
    frameLength: frameLength(options.frameLength, lowDelay),
    crc: flag(options.crc, 'crc'),
    mpeg2: flag(options.mpeg2, 'mpeg2'),
    channelMode: oneOf(channelMode, CHANNEL_MODES, 'channelMode'),
    ldSbr: ldSbrOption(options.ldSbr),
    afterburner: flag(options.afterburner, 'afterburner'),
    metadata: metadataOption(options.metadata),
  };
}

export function registerAacIpc(): void {
  const aac = getAacService();

  handleTrusted(IPC_CHANNELS.AAC_INFO, () => aac.info());

  handleTrusted(IPC_CHANNELS.AAC_DECODE, (_event, data, options) =>
    aac.decode(bytes(data, 'data'), decoderOptions(options)));

  handleTrusted(IPC_CHANNELS.AAC_ENCODE, (_event, pcm, options) =>
    aac.encode(samples(pcm, 'pcm'), encoderOptions(options)));

  handleTrusted(IPC_CHANNELS.AAC_DECODER_OPEN, (_event, options) => aac.openDecoder(decoderOptions(options)));
  handleTrusted(IPC_CHANNELS.AAC_DECODER_DECODE, (_event, id, chunk) =>
    aac.decodeChunk(sessionId(id), bytes(chunk, 'chunk')));
  handleTrusted(IPC_CHANNELS.AAC_DECODER_CLOSE, (_event, id) => aac.closeDecoder(sessionId(id)));

  handleTrusted(IPC_CHANNELS.AAC_ENCODER_OPEN, (_event, options) => aac.openEncoder(encoderOptions(options)));
  handleTrusted(IPC_CHANNELS.AAC_ENCODER_ENCODE, (_event, id, pcm) =>
    aac.encodeChunk(sessionId(id), samples(pcm, 'pcm')));
  handleTrusted(IPC_CHANNELS.AAC_ENCODER_FLUSH, (_event, id) => aac.flushEncoder(sessionId(id)));
  handleTrusted(IPC_CHANNELS.AAC_ENCODER_CLOSE, (_event, id) => aac.closeEncoder(sessionId(id)));
}
