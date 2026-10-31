<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

use enshrined\svgSanitize\Sanitizer;

final class SvgDiagram
{
    public function sanitize(string $svg, string $fontBytes): string
    {
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $svg) === 1) {
            throw new \RuntimeException('Diagram SVG must not contain document types or entities.');
        }
        $document = $this->document($svg);
        $this->removeUnsafeContent($document);
        $sanitizer = new Sanitizer();
        $sanitizer->removeRemoteReferences(true);
        $clean = $sanitizer->sanitize($document->saveXML() ?: '');
        if (!is_string($clean)) {
            throw new \RuntimeException('Diagram SVG sanitization failed.');
        }
        $document = $this->document($clean);
        $this->assertSafe($document);
        if ($fontBytes === '') {
            throw new \RuntimeException('Diagram font payload is empty.');
        }
        $style = $document->createElementNS('http://www.w3.org/2000/svg', 'style');
        $style->textContent = '@font-face{font-family:Inter;font-style:normal;font-weight:400;src:url(data:font/woff2;base64,' . base64_encode($fontBytes) . ') format("woff2")}';
        $document->documentElement->appendChild($style);
        return $document->saveXML($document->documentElement) ?: throw new \RuntimeException('Diagram SVG serialization failed.');
    }

    private function document(string $svg): \DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($svg, LIBXML_NONET) || $document->documentElement?->localName !== 'svg' || $document->documentElement->namespaceURI !== 'http://www.w3.org/2000/svg') {
                throw new \RuntimeException('Diagram is not a valid SVG document.');
            }
            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function removeUnsafeContent(\DOMDocument $document): void
    {
        foreach (iterator_to_array($document->getElementsByTagName('*')) as $element) {
            if (strtolower($element->localName) === 'a') {
                while ($element->firstChild !== null) {
                    $element->parentNode?->insertBefore($element->firstChild, $element);
                }
                $element->parentNode?->removeChild($element);
                continue;
            }
            if (in_array(strtolower($element->localName), ['script', 'foreignobject', 'image', 'animate', 'animatetransform', 'animatemotion', 'set'], true)) {
                $element->parentNode?->removeChild($element);
                continue;
            }
            if (strtolower($element->localName) === 'style' && !$this->safeCss($element->textContent)) {
                $element->parentNode?->removeChild($element);
                continue;
            }
            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->localName);
                if (str_starts_with($name, 'on') || $name === 'base' || ($name === 'href' && !str_starts_with($attribute->value, '#')) || !$this->safeCss($attribute->value)) {
                    $element->removeAttributeNode($attribute);
                }
            }
        }
        foreach (iterator_to_array($document->childNodes) as $node) {
            if ($node instanceof \DOMProcessingInstruction) {
                $document->removeChild($node);
            }
        }
    }

    private function assertSafe(\DOMDocument $document): void
    {
        foreach ($document->getElementsByTagName('*') as $element) {
            if (in_array(strtolower($element->localName), ['script', 'foreignobject', 'image', 'a', 'animate', 'animatetransform', 'animatemotion', 'set'], true)) {
                throw new \RuntimeException('Diagram SVG contains an unsafe element.');
            }
            if (strtolower($element->localName) === 'style' && !$this->safeCss($element->textContent)) {
                throw new \RuntimeException('Diagram SVG contains unsafe CSS.');
            }
            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->localName);
                if (str_starts_with($name, 'on') || $name === 'base' || ($name === 'href' && !str_starts_with($attribute->value, '#')) || !$this->safeCss($attribute->value)) {
                    throw new \RuntimeException('Diagram SVG contains an unsafe reference.');
                }
            }
        }
    }

    private function safeCss(string $value): bool
    {
        if (str_contains($value, '\\') || str_contains($value, '@') || str_contains($value, '/*') || preg_match('/(?:javascript|data|https?|file)\s*:|(?:image-set|image|cross-fade|src|paint|element|expression)\s*\(/i', $value) === 1) {
            return false;
        }
        $withoutLocalUrls = preg_replace('/url\(\s*["\']?#[a-zA-Z0-9_.:-]+["\']?\s*\)/i', '', $value) ?? $value;
        return preg_match('/url\s*\(/i', $withoutLocalUrls) !== 1;
    }
}
