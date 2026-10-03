<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

/** Exposes Symfony's prepared file boundaries to Swoole's sendfile transport. */
final class SwooleBinaryFileResponse extends BinaryFileResponse
{
    public function prepare(Request $request): static
    {
        $preparedRequest = clone $request;
        $preparedRequest->headers->remove('Range');
        $unsatisfiable = false;
        $fileSize = $this->getFile()->getSize();

        if ($this->isSuccessful() && $request->isMethod('GET') && $fileSize !== false &&
            $request->headers->has('Range') && $this->allowsRange($request)) {
            $range = $request->headers->get('Range');
            if (preg_match('/^bytes=(\d*)-(\d*)$/D', $range ?? '', $matches) &&
                ($matches[1] !== '' || $matches[2] !== '')) {
                [$start, $end] = [$matches[1], $matches[2]];
                if ($start === '') {
                    $suffix = $this->boundedDecimal($end, $fileSize);
                    $unsatisfiable = $suffix === 0 || $fileSize === 0;
                    $start = $fileSize - $suffix;
                    $end = $fileSize - 1;
                } elseif ($end === '' || $this->compareDecimals($start, $end) <= 0) {
                    $start = $this->boundedDecimal($start, $fileSize);
                    $unsatisfiable = $start >= $fileSize;
                    $end = $end === '' ? $fileSize - 1 : $this->boundedDecimal($end, max(0, $fileSize - 1));
                } else {
                    // Ignore malformed reversed ranges, like unsupported multipart ranges.
                    $start = null;
                }
                if (!$unsatisfiable && $start !== null) {
                    $preparedRequest->headers->set('Range', sprintf('bytes=%d-%d', $start, $end));
                }
            }
        }

        parent::prepare($preparedRequest);
        if ($unsatisfiable) {
            $this->setStatusCode(416);
            $this->headers->set('Content-Range', sprintf('bytes */%d', $fileSize));
            $this->headers->set('Content-Length', '0');
            $this->offset = 0;
            $this->maxlen = 0;
        } elseif (!$this->isSuccessful() && !$this->isEmpty()) {
            // BinaryFileResponse suppresses unsuccessful bodies; match that on
            // the wire as well, including when prepare() runs more than once.
            $this->headers->set('Content-Length', '0');
            $this->maxlen = 0;
        }
        return $this;
    }

    private function allowsRange(Request $request): bool
    {
        $validator = $request->headers->get('If-Range');
        if ($validator === null) {
            return true;
        }
        // Weak entity tags cannot validate the byte representation being resumed.
        $etag = $this->getEtag();
        if ($etag !== null && !str_starts_with($etag, 'W/') && $validator === $etag) {
            return true;
        }
        $lastModified = $this->getLastModified();
        return $lastModified !== null && $validator === $lastModified->format('D, d M Y H:i:s') . ' GMT';
    }

    /** Cap decimal input before casting so arbitrarily large ranges cannot overflow. */
    private function boundedDecimal(string $value, int $limit): int
    {
        return $this->compareDecimals($value, (string) $limit) >= 0 ? $limit : (int) $value;
    }

    private function compareDecimals(string $first, string $second): int
    {
        $first = ltrim($first, '0');
        $second = ltrim($second, '0');
        return strlen($first) <=> strlen($second) ?: strcmp($first, $second);
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getLength(): int
    {
        if (!$this->isSuccessful() || $this->maxlen === 0) {
            return 0;
        }
        return $this->maxlen >= 0 ? $this->maxlen : max(0, (int) $this->getFile()->getSize() - $this->offset);
    }
}
