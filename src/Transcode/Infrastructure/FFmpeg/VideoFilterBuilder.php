<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\ColorSpace;
use App\Transcode\Domain\ValueObject\HardwareAccelerator;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\ToneMapMethod;
use App\Transcode\Domain\ValueObject\VideoProbeResult;

final class VideoFilterBuilder
{
    /** @var list<string> */
    private array $filters = [];

    private function __construct(
        private readonly HardwareAccelerator $accelerator,
    ) {
    }

    public static function create(HardwareAccelerator $accelerator = HardwareAccelerator::None): self
    {
        return new self($accelerator);
    }

    public function scale(QualityTier $tier): self
    {
        $filter = match ($this->accelerator) {
            HardwareAccelerator::Nvenc => sprintf('scale_cuda=%d:%d', $tier->width, $tier->height),
            HardwareAccelerator::Vaapi, HardwareAccelerator::Amf => sprintf('scale_vaapi=%d:%d', $tier->width, $tier->height),
            HardwareAccelerator::Qsv => sprintf('scale_qsv=%d:%d', $tier->width, $tier->height),
            default => sprintf('scale=%d:%d:flags=fast_bilinear', $tier->width, $tier->height),
        };
        $this->filters[] = $filter;

        return $this;
    }

    public function deinterlace(): self
    {
        $filter = match ($this->accelerator) {
            HardwareAccelerator::Nvenc => 'yadif_cuda',
            HardwareAccelerator::Vaapi, HardwareAccelerator::Amf => 'deinterlace_vaapi',
            HardwareAccelerator::Qsv => 'deinterlace_qsv',
            default => 'yadif',
        };
        $this->filters[] = $filter;

        return $this;
    }

    public function framerate(float $targetFps): self
    {
        if ($targetFps > 0) {
            $this->filters[] = sprintf('fps=%.3f', $targetFps);
        }

        return $this;
    }

    public function tonemap(VideoProbeResult $probe, ToneMapMethod $method, ?ColorSpace $targetSpace = null): self
    {
        if ($probe->colorSpace === null || !$probe->colorSpace->isHdr()) {
            return $this;
        }

        if ($method === ToneMapMethod::None) {
            return $this;
        }

        $target = $targetSpace ?? ColorSpace::bt709();

        // NVIDIA: full hardware tonemap
        if ($this->accelerator->supportsHardwareTonemap()) {
            // Extract tonemap value: ffmpegParam() returns 'tonemap=tonemap=hable:desat=0'
            // We need the part after the first 'tonemap=': 'tonemap=hable:desat=0'
            $param = $method->ffmpegParam();
            $prefix = 'tonemap=';
            $prefixLen = strlen($prefix);
            if (str_starts_with($param, $prefix)) {
                $tonemapValue = substr($param, $prefixLen);
            } else {
                // Fallback: use the raw param as-is for forward compatibility
                $tonemapValue = $param;
            }
            $this->filters[] = sprintf('tonemap_cuda=%s', $tonemapValue);

            return $this;
        }

        // Other hardware: hybrid mode — hwdownload → SW tonemap → hwupload
        if ($this->accelerator !== HardwareAccelerator::None) {
            $this->filters[] = 'hwdownload,format=nv12';
            $this->filters[] = sprintf(
                'zscale=t=linear:npl=100,format=gbrpf32le,zscale=p=%s,%s,zscale=t=%s:m=%s:r=tv,format=yuv420p',
                $target->primaries,
                $method->ffmpegParam(),
                $target->transfer,
                $target->matrix,
            );
            $this->filters[] = 'hwupload';

            return $this;
        }

        // Software: existing chain unchanged
        $this->filters[] = sprintf(
            'zscale=t=linear:npl=100,format=gbrpf32le,zscale=p=%s,%s,zscale=t=%s:m=%s:r=tv,format=yuv420p',
            $target->primaries,
            $method->ffmpegParam(),
            $target->transfer,
            $target->matrix,
        );

        return $this;
    }

    /**
     * Append hwupload at the end of the filter chain.
     * Used when software filters feed a hardware encoder.
     */
    public function appendHwupload(): self
    {
        if ($this->accelerator !== HardwareAccelerator::None && !empty($this->filters)) {
            $this->filters[] = 'hwupload';
        }

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    public function build(): string
    {
        if (empty($this->filters)) {
            return '';
        }

        return implode(',', $this->filters);
    }
}
