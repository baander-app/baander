<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests\Website;

use Baander\Tooling\Website\SvgDiagram;
use PHPUnit\Framework\TestCase;

final class SvgDiagramTest extends TestCase
{
    public function testPreservesDiagramGeometryLabelsMarkersAndEmbedsOnlyTrustedFont(): void
    {
        $source = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 200" width="400" height="200">
<style>.edge{stroke:#555;marker-end:url(#arrow)}text{font-family:Inter;font-size:16px}</style>
<defs><marker id="arrow" viewBox="0 0 10 10"><path d="M0 0L10 5L0 10z"/></marker></defs>
<path class="edge" d="M0 0L100 100" marker-end="url(#arrow)"/>
<text x="10" y="20">Bånder &amp; “music” → ready</text>
</svg>
SVG;
        $svg = new SvgDiagram()->sanitize($source, 'trusted-font-bytes');
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($svg));
        self::assertSame('0 0 400 200', $document->documentElement->getAttribute('viewBox'));
        self::assertSame('400', $document->documentElement->getAttribute('width'));
        self::assertSame('200', $document->documentElement->getAttribute('height'));
        self::assertStringContainsString('Bånder & “music” → ready', $document->textContent);
        self::assertStringContainsString('url(#arrow)', $svg);
        self::assertStringContainsString('id="arrow"', $svg);
        self::assertStringContainsString('data:font/woff2;base64,' . base64_encode('trusted-font-bytes'), $svg);
        self::assertSame(1, substr_count($svg, '@font-face'));
    }

    public function testRemovesActiveContentAndExternalAndSourceDataResources(): void
    {
        $source = <<<'SVG'
<?xml-stylesheet href="https://baander.app/unsafe.css"?>
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" onload="alert(1)">
<script>alert(1)</script><foreignObject><div>unsafe</div></foreignObject>
<image href="data:image/svg+xml;base64,dW5zYWZl"/>
<a href="https://baander.app/click"><text>click</text></a>
<use href="https://baander.app/external.svg#node"/><use xlink:href="data:image/svg+xml,unsafe"/>
<style>@import "https://baander.app/unsafe.css";</style>
<style>@font-face{font-family:Inter;src:url(data:font/woff2;base64,dW5zYWZl)}</style>
<style>.bad{fill:u\72l(https://baander.app/unsafe)}</style>
<style>.bad{background:image-set("//baander.app/resource" 1x)}</style>
<path onclick="alert(1)" fill="url(https://baander.app/image)" style="fill:url(data:image/png,unsafe)"/>
<animate attributeName="href" values="javascript:alert(1)"/>
<text>Safe label</text>
</svg>
SVG;
        $svg = new SvgDiagram()->sanitize($source, 'font');
        foreach (['script', 'foreignObject', '<image', '<a ', 'onload', 'onclick', 'https://baander.app', '@import', 'dW5zYWZl', 'u\\72l', '<animate', 'xml-stylesheet'] as $unsafe) {
            self::assertStringNotContainsString($unsafe, $svg);
        }
        self::assertSame(1, substr_count($svg, 'data:'));
        self::assertStringContainsString('Safe label', $svg);
        self::assertStringContainsString('click', $svg);
    }

    public function testRejectsDocumentTypesAndEntitiesWithoutLoadingResources(): void
    {
        $this->expectException(\RuntimeException::class);
        new SvgDiagram()->sanitize('<!DOCTYPE svg [<!ENTITY label SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&label;</text></svg>', 'font');
    }

    public function testRejectsMalformedSvg(): void
    {
        $this->expectException(\RuntimeException::class);
        new SvgDiagram()->sanitize('<svg><text></svg>', 'font');
    }

    public function testRejectsEmptyFont(): void
    {
        $this->expectException(\RuntimeException::class);
        new SvgDiagram()->sanitize('<svg xmlns="http://www.w3.org/2000/svg"/>', '');
    }
}
