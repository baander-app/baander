<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Local;

use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Metadata\Application\DTO\Video\VideoSidecar;
use App\Metadata\Application\DTO\Video\VideoSidecarKind;
use App\Shared\Domain\Model\Uuid;
use DOMDocument;
use DOMElement;
use DOMNode;

final readonly class NfoReader
{
    private const int MAX_BYTES = 1_048_576;

    public function __construct(private LibraryMediaFilesInterface $files) {}

    /** @return list<DOMElement> */
    public function read(Uuid $library, VideoSidecar $sidecar): array
    {
        $rootName = match ($sidecar->kind) {
            VideoSidecarKind::SeriesNfo => 'tvshow',
            VideoSidecarKind::SeasonNfo => 'season',
            VideoSidecarKind::VideoNfo => 'episodedetails',
            VideoSidecarKind::MovieNfo => 'movie',
            default => null,
        };
        if ($rootName === null) {
            return [];
        }
        $xml = $this->files->readSidecar($library, $sidecar->path, self::MAX_BYTES);
        if ($xml === null || trim($xml) === '') {
            return [];
        }
        $document = self::document($xml);
        if ($document !== null) {
            $root = $document->documentElement;

            return $root?->tagName === $rootName ? [$root] : [];
        }
        if ($sidecar->kind !== VideoSidecarKind::VideoNfo) {
            return [];
        }

        return self::episodeFragments($xml, $rootName);
    }

    private static function document(string $xml): ?DOMDocument
    {
        $document = new DOMDocument();
        $options = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING;
        if (defined('LIBXML_NO_XXE')) {
            $options |= LIBXML_NO_XXE;
        }

        return $document->loadXML($xml, $options) ? $document : null;
    }

    /** @return list<DOMElement> */
    private static function episodeFragments(string $xml, string $rootName): array
    {
        // Keep the optional BOM and XML declaration outside the synthetic root.
        preg_match('/\A(?:\xEF\xBB\xBF)?(?:<\?xml\s[^?]*\?>)?/', $xml, $prefix);
        $header = $prefix[0] ?? '';
        $body = substr($xml, strlen($header));
        $root = self::document($header . '<episodes>' . $body . '</episodes>')?->documentElement;
        if ($root === null) {
            return [];
        }
        $episodes = [];
        foreach ($root->childNodes as $node) {
            if ($node instanceof DOMElement && $node->tagName === $rootName) {
                $episodes[] = $node;
            } elseif (!in_array($node->nodeType, [XML_COMMENT_NODE, XML_PI_NODE], true)
                && ($node->nodeType !== XML_TEXT_NODE || trim($node->nodeValue ?? '') !== '')
            ) {
                return [];
            }
        }

        return $episodes;
    }

    /** @return list<DOMElement> */
    public static function elements(DOMElement $parent, string $name): array
    {
        $elements = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->tagName === $name) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    public static function text(DOMElement $element, ?string $childName = null): ?string
    {
        $node = $childName === null ? $element : self::firstChild($element, $childName);
        $text = $node === null ? '' : trim(self::textNodes($node));

        return $text !== '' ? $text : null;
    }

    private static function firstChild(DOMElement $parent, string $name): ?DOMElement
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->tagName === $name) {
                return $node;
            }
        }

        return null;
    }

    public static function attribute(DOMElement $element, string $name): string
    {
        $attribute = $element->getAttributeNode($name);

        return $attribute === false ? '' : trim(self::textNodes($attribute));
    }

    private static function textNodes(DOMNode $node): string
    {
        $value = '';
        // DOMElement::textContent expands declared internal entities on access.
        foreach ($node->childNodes as $child) {
            $value .= match ($child->nodeType) {
                XML_ELEMENT_NODE => self::textNodes($child),
                XML_TEXT_NODE, XML_CDATA_SECTION_NODE => $child->nodeValue ?? '',
                default => '',
            };
        }

        return $value;
    }
}
