<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\EventListener;

use App\Shared\Infrastructure\EventListener\SecurityHeadersListener;
use App\Shared\Infrastructure\EventListener\ViteScriptNonceListener;
use Pentatrion\ViteBundle\Event\RenderAssetTagEvent;
use Pentatrion\ViteBundle\Model\Tag;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ViteScriptNonceListenerTest extends TestCase
{
    public function testScriptTagsIncludingTheInlineDevelopmentPreambleCarryTheRequestNonce(): void
    {
        $request = Request::create('https://baander.app/');
        $request->attributes->set(SecurityHeadersListener::NONCE_ATTRIBUTE, 'request-nonce');
        $stack = new RequestStack();
        $stack->push($request);
        $listener = new ViteScriptNonceListener($stack);

        $module = new Tag(Tag::SCRIPT_TAG, ['type' => 'module', 'src' => '/assets/app.js']);
        $preamble = new Tag(Tag::SCRIPT_TAG, ['type' => 'module'], 'window.__vite_plugin_react_preamble_installed__ = true');
        $stylesheet = new Tag(Tag::LINK_TAG, ['rel' => 'stylesheet', 'href' => '/assets/app.css']);
        foreach ([$module, $preamble, $stylesheet] as $tag) {
            $listener(new RenderAssetTagEvent(false, $tag));
        }

        self::assertSame('request-nonce', $module->getAttribute('nonce'));
        self::assertSame('request-nonce', $preamble->getAttribute('nonce'));
        self::assertNull($stylesheet->getAttribute('nonce'));
    }

    public function testWithoutARequestNonceTagsAreLeftAlone(): void
    {
        $tag = new Tag(Tag::SCRIPT_TAG, ['type' => 'module', 'src' => '/assets/app.js']);
        (new ViteScriptNonceListener(new RequestStack()))(new RenderAssetTagEvent(true, $tag));

        self::assertNull($tag->getAttribute('nonce'));
    }
}
