import { handleTrusted } from '../guard';
import { IPC_CHANNELS } from '../channels';
import { getAacService } from '../../main/services/aac.service';
import type { AacDecoderOptions, AacEncoderOptions, AacRateControlMode } from '@baander/aac-native';

const SAMPLE_RATES = new Set([8000, 11025, 12000, 16000, 22050, 24000, 32000, 44100, 48000, 64000, 88200, 96000]);
const MODES = new Set<AacRateControlMode>(['cbr', 'abr', 'cvbr', 'tvbr']);

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
  const { sampleRate, channels } = options;
  if (sampleRate !== undefined && !(typeof sampleRate === 'number' && SAMPLE_RATES.has(sampleRate))) invalid('sampleRate');
  if (channels !== undefined && channels !== 1 && channels !== 2) invalid('channels');
  return { sampleRate: sampleRate as number | undefined, channels: channels as number | undefined };
}

function encoderOptions(value: unknown): AacEncoderOptions {
  const options = (value ?? {}) as Record<string, unknown>;
  if (typeof options !== 'object' || Array.isArray(options)) invalid('encoder options');
  const { sampleRate, channels, bitrate, mode, quality } = options;
  if (sampleRate !== undefined && !(typeof sampleRate === 'number' && SAMPLE_RATES.has(sampleRate))) invalid('sampleRate');
  if (channels !== undefined && channels !== 1 && channels !== 2) invalid('channels');
  if (bitrate !== undefined && !(typeof bitrate === 'number' && Number.isInteger(bitrate) && bitrate >= 8000 && bitrate <= 1_000_000)) invalid('bitrate');
  if (mode !== undefined && !(typeof mode === 'string' && MODES.has(mode as AacRateControlMode))) invalid('mode');
  if (quality !== undefined && !(typeof quality === 'number' && Number.isInteger(quality) && quality >= 1 && quality <= 100)) invalid('quality');
  return {
    sampleRate: sampleRate as number | undefined,
    channels: channels as number | undefined,
    bitrate: bitrate as number | undefined,
    mode: mode as AacRateControlMode | undefined,
    quality: quality as number | undefined,
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
