<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class RenderedCode implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): ?string
    {
        FencedCode::assertInstanceOf($node);
        $html = $node->data->get('website/html', null);
        return is_string($html) ? $html : null;
    }
}
