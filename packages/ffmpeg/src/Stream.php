<?php

namespace Baander\Ffmpeg;

use Baander\Ffmpeg\Exception\InvalidArgumentException;
use Baander\Ffmpeg\Exception\RuntimeException;
use Baander\Ffmpeg\Filters\StreamFilterInterface;
use Baander\Ffmpeg\Traits\Formats;
use FFMpeg\Exception\ExceptionInterface;

abstract class Stream implements StreamInterface
{
    use Formats;

    /** @var string */
    protected $path;
    /** @var Media */
    private $media;
    /** @var string */
    private $tmp_dir = '';

    /**
     * Stream constructor.
     * @param Media $media
     */
    public function __construct(Media $media)
    {
        $this->media = $media;
        $this->path = $media->getPathfile();
    }

    /**
     * @return Media
     */
    public function getMedia(): Media
    {
        return $this->media;
    }

    /**
     * @param string $path
     * @param array $clouds
     * @return mixed
     */
    public function save(string $path = null, array $clouds = []): Stream
    {
        $this->paths($path, $clouds);
        $this->run();
        $this->clouds($clouds, $path);

        return $this;
    }

    /**
     * @param $path
     * @param $clouds
     */
    private function paths(?string $path, array $clouds): void
    {
        if (!empty($clouds)) {
            $this->tmp_dir = File::tmpDir();
            $this->path = $this->tmp_dir . basename($clouds['options']['filename'] ?? $path ?? $this->path);
        } else if (!is_null($path)) {
            if (strlen($path) > PHP_MAXPATHLEN) {
                throw new InvalidArgumentException("The path is too long");
            }

            File::makeDir(dirname($path));
            $this->path = $path;
        } else if ($this->media->isTmp()) {
            throw new InvalidArgumentException("You need to specify a path. It is not possible to save to a tmp directory");
        }
    }

    /**
     * Run FFmpeg to package media content
     */
    private function run(): void
    {
        $this->media->addFilter($this->getFilter());

        $commands = (new CommandBuilder($this->media, $this->getFormat()))->build($this->getFormat(), $this->getPath());
        $pass = $this->format->getPasses();
        $listeners = $this->format->createProgressListener($this->media->baseMedia(), $this->media->getFFProbe(), 1, $pass);

        try {
            $this->media->getFFMpegDriver()->command($commands, false, $listeners);
        } catch (ExceptionInterface $e) {
            throw new RuntimeException("An error occurred while saving files: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @return StreamFilterInterface
     */
    abstract protected function getFilter(): StreamFilterInterface;

    /**
     * @return string
     */
    abstract protected function getPath(): string;

    /**
     * @param string|null $path
     */
    private function moveTmp(?string $path): void
    {
        if ($this->isTmpDir() && !is_null($path)) {
            File::move($this->tmp_dir, dirname($path));
            $this->path = $path;
            $this->tmp_dir = '';
        }
    }

    /**
     * @return bool
     */
    public function isTmpDir(): bool
    {
        return (bool)$this->tmp_dir;
    }

    /**
     * @param string $url
     */
    public function live(string $url): void
    {
        $this->path = $url;
        $this->run();
    }

    /**
     * @return Metadata
     */
    public function metadata(): Metadata
    {
        return new Metadata($this);
    }

    /**
     * clear tmp files
     */
    public function __destruct()
    {
        // make sure that FFmpeg process has benn terminated
        sleep(1);
        File::remove($this->tmp_dir);

        if ($this->media->isTmp()) {
            File::remove($this->media->getPathfile());
        }
    }

    /**
     * @return string
     */
    protected function getFilePath(): string
    {
        return str_replace(
            "\\",
            "/",
            $this->pathInfo(PATHINFO_DIRNAME) . "/" . $this->pathInfo(PATHINFO_FILENAME),
        );
    }

    /**
     * @param int $option
     * @return string
     */
    public function pathInfo(int $option): string
    {
        return pathinfo($this->path, $option);
    }
}