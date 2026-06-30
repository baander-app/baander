<?php

declare(strict_types=1);

namespace App\Transcode\Domain\ValueObject;

enum HardwareAccelerator: string
{
    case None = 'none';
    case Nvenc = 'nvenc';
    case Vaapi = 'vaapi';
    case VideoToolbox = 'videotoolbox';
    case Qsv = 'qsv';
    case Amf = 'amf';

    /**
     * FFmpeg -hwaccel method name.
     */
    public function ffmpegHwaccelMethod(): string
    {
        return match ($this) {
            self::None             => '',
            self::Nvenc            => 'cuda',
            self::Vaapi, self::Amf => 'vaapi',
            self::VideoToolbox     => 'videotoolbox',
            self::Qsv              => 'qsv',
        };
    }

    /**
     * Whether this accelerator requires a device path (e.g. /dev/dri/renderD128).
     */
    public function requiresDevicePath(): bool
    {
        return match ($this) {
            self::None, self::Nvenc, self::VideoToolbox => false,
            self::Vaapi, self::Qsv, self::Amf => true,
        };
    }

    /**
     * Default device path for this accelerator type.
     */
    public function defaultDevicePath(): string
    {
        return match ($this) {
            self::None, self::Nvenc, self::VideoToolbox => '',
            self::Vaapi, self::Amf, self::Qsv           => '/dev/dri/renderD128',
        };
    }

    /**
     * FFmpeg -hwaccel_output_format value for keeping frames in GPU memory.
     */
    public function hwaccelOutputFormat(): string
    {
        return match ($this) {
            self::None, self::VideoToolbox => '',
            self::Nvenc                    => 'cuda',
            self::Vaapi                    => 'vaapi',
            self::Qsv                      => 'qsv',
            self::Amf                      => 'vaapi',
        };
    }

    /**
     * Whether this accelerator supports hardware tonemapping.
     * Only NVIDIA has a usable tonemap_cuda filter.
     */
    public function supportsHardwareTonemap(): bool
    {
        return $this === self::Nvenc;
    }

    /**
     * HEVC encoder name for this accelerator.
     */
    public function hevcEncoder(): string
    {
        return match ($this) {
            self::None => 'libx265',
            self::Nvenc => 'hevc_nvenc',
            self::Vaapi => 'hevc_vaapi',
            self::VideoToolbox => 'hevc_videotoolbox',
            self::Qsv => 'hevc_qsv',
            self::Amf => 'hevc_amf',
        };
    }

    /**
     * H.264 encoder name for this accelerator.
     */
    public function h264Encoder(): string
    {
        return match ($this) {
            self::None => 'libx264',
            self::Nvenc => 'h264_nvenc',
            self::Vaapi => 'h264_vaapi',
            self::VideoToolbox => 'h264_videotoolbox',
            self::Qsv => 'h264_qsv',
            self::Amf => 'h264_amf',
        };
    }

    /**
     * Hardware decoder name for a given source codec.
     */
    public function decoderForCodec(string $sourceCodec): string
    {
        return match ($this) {
            self::None => '',
            self::Nvenc => match (strtolower($sourceCodec)) {
                'h264', 'avc' => 'h264_cuvid',
                'hevc', 'h265' => 'hevc_cuvid',
                'av1' => 'av1_cuvid',
                'vp9' => 'vp9_cuvid',
                'mpeg2video' => 'mpeg2_cuvid',
                'mpeg4' => 'mpeg4_cuvid',
                default => '',
            },
            self::Vaapi, self::Amf => '', // VAAPI uses hwaccel method for decode, no explicit -c:v
            self::VideoToolbox => '', // VideoToolbox handles decode via hwaccel method
            self::Qsv => match (strtolower($sourceCodec)) {
                'h264', 'avc' => 'h264_qsv',
                'hevc', 'h265' => 'hevc_qsv',
                'vp9' => 'vp9_qsv',
                'av1' => 'av1_qsv',
                'mpeg2video' => 'mpeg2_qsv',
                default => '',
            },
        };
    }

    /**
     * Resolve from an encoder name string.
     */
    public static function fromEncoderName(string $encoder): self
    {
        return match (true) {
            str_contains($encoder, 'nvenc') => self::Nvenc,
            str_contains($encoder, 'vaapi') => self::Vaapi,
            str_contains($encoder, 'videotoolbox') => self::VideoToolbox,
            str_contains($encoder, '_qsv') => self::Qsv,
            str_contains($encoder, '_amf') => self::Amf,
            default => self::None,
        };
    }
}
