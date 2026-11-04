<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

use Baander\Tooling\Filesystem\Paths;
use Closure;
use DOMDocument;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalink;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\RawMarkupContainerInterface;
use League\CommonMark\Node\StringContainerHelper;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class WebsiteBuilder
{
    private readonly Environment $environment;
    private readonly string $root;
    /** @var array<string,array{source:string,url:string,title:string,guide:?string,html:string,headings:list<array{id:string,title:string,level:int}>}> */
    private array $pages = [];
    /** @var array<string,Document> */
    private array $documents = [];
    /** @var array<string,string> */
    private array $assets = [];
    /** @var array<string,list<array{url:string,title:string}>> */
    private array $navigation = ['operator' => [], 'developer' => []];

    public function __construct(string $root, private readonly string $revisionUrl)
    {
        $this->root = realpath($root) ?: throw new RuntimeException('Repository root does not exist.');
        if (!preg_match('~^https://github\.com/baander-app/baander/(?:tree|blob)/[a-zA-Z0-9._-]+$~', $revisionUrl)) {
            throw new RuntimeException('Source browser URL must identify a Baander repository revision.');
        }
        $this->environment = new Environment([
            "html_input" => "escape",
            "allow_unsafe_links" => false,
            "slug_normalizer" => ["instance" => new HeadingSlugNormalizer(), "unique" => "document"],
            "heading_permalink" => ["id_prefix" => "", "fragment_prefix" => "", "apply_id_to_heading" => true, "insert" => "after"],
        ]);
        $this->environment->addExtension(new CommonMarkCoreExtension());
        $this->environment->addExtension(new TableExtension());
        $this->environment->addExtension(new HeadingPermalinkExtension());
        $this->environment->addRenderer(FencedCode::class, new RenderedCode(), 100);
    }

    /**
     * The callback completes search or packaging inside the unpublished staging tree.
     * Any exception retains the previous complete artifact.
     * @param ?Closure(string,array<string,array{source:string,url:string,title:string,guide:?string,html:string,headings:list<array{id:string,title:string,level:int}>}>):void $afterRender
     * @param ?Closure(list<array{id:string,source:string,language:string,page:string,ordinal:int}>,string):array<string,string> $renderBlocks
     * @param list<array{template:string,url:string,title:string,description:string,stylesheet?:string,script?:string,data:array<string,mixed>}> $publicPages
     * @return array<string,array{source:string,url:string,title:string,guide:?string,html:string,headings:list<array{id:string,title:string,level:int}>}>
     */
    public function build(?Closure $afterRender = null, ?Closure $renderBlocks = null, array $publicPages = []): array
    {
        $this->pages = $this->documents = $this->assets = [];
        $this->navigation = ['operator' => [], 'developer' => []];
        $this->checkOutput();
        $this->discover();
        $this->transformLinks();
        $this->buildNavigation();
        $staging = $this->root . '/var/website-build-' . bin2hex(random_bytes(8));
        mkdir($staging, 0755, true);
        try {
            $this->renderBlocks($staging, $renderBlocks);
            $renderer = new HtmlRenderer($this->environment);
            foreach ($this->pages as $source => &$page) {
                $page['html'] = (string) $renderer->renderDocument($this->documents[$source]);
                $this->write($staging . $page['url'] . 'index.html', $this->template('page', $page));
            }
            unset($page);
            $this->write($staging . '/index.html', $this->template('home', ['source' => '', 'url' => '/', 'title' => 'Bånder', 'guide' => null, 'html' => '', 'headings' => []]));
            $this->write($staging . '/404.html', $this->template('404', ['source' => '', 'url' => '/404.html', 'title' => 'Page not found', 'guide' => null, 'html' => '', 'headings' => []]));
            $routes = array_fill_keys(array_column($this->pages, 'url'), true);
            $routes['/'] = $routes['/404.html'] = true;
            foreach ($publicPages as $descriptor) {
                $url = $descriptor['url'];
                if (!preg_match('~^/(?:[a-z0-9]+(?:-[a-z0-9]+)*/)+$~', $url) || isset($routes[$url]) || str_starts_with($url, '/docs/')) {
                    throw new RuntimeException('Invalid or duplicate public page URL: ' . $url);
                }
                if (!preg_match('/^[a-z][a-z0-9-]*$/', $descriptor['template'])) {
                    throw new RuntimeException('Invalid public page template.');
                }
                $routes[$url] = true;
                $page = $descriptor + ['source' => '', 'guide' => null, 'html' => '', 'headings' => []];
                $this->write($staging . $url . 'index.html', $this->template($descriptor['template'], $page, $descriptor['data']));
            }
            foreach ($this->assets as $url => $source) {
                $this->write($staging . $url, (string) file_get_contents($source));
            }
            $this->copySiteAssets($staging);
            $afterRender?->__invoke($staging, $this->pages);
            $this->validateArtifact($staging);
            $this->promote($staging);
        } catch (Throwable $error) {
            $this->remove($staging);
            throw $error;
        }
        return $this->pages;
    }

    /** @param ?Closure(list<array{id:string,source:string,language:string,page:string,ordinal:int}>,string):array<string,string> $renderBlocks */
    private function renderBlocks(string $staging, ?Closure $renderBlocks): void
    {
        $blocks = [];
        $nodes = [];
        foreach ($this->documents as $source => $document) {
            $ordinal = 0;
            foreach ($document->iterator() as $node) {
                if (!$node instanceof FencedCode) {
                    continue;
                }
                ++$ordinal;
                $language = strtolower($node->getInfoWords()[0] ?? '');
                if (str_starts_with($language, 'language-')) {
                    $language = substr($language, strlen('language-'));
                }
                $id = 'block-' . substr(hash('sha256', $source . ':' . $ordinal), 0, 16);
                if ($language === 'php') {
                    try {
                        $html = new CodeHighlighter()->highlight($node->getLiteral());
                    } catch (\Throwable $error) {
                        fwrite(STDERR, $source . ', block ' . $ordinal . ': PHP highlighting failed; using escaped source: ' . $error->getMessage() . "\n");
                        $html = htmlspecialchars($node->getLiteral(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    }
                    $node->data->set('website/html', '<pre><code class="language-php">' . $html . '</code></pre>');
                    continue;
                }
                if ($renderBlocks === null) {
                    if ($language === 'mermaid') {
                        throw new RuntimeException($source . ', block ' . $ordinal . ': Mermaid rendering requires the local build helper.');
                    }
                    continue;
                }
                $blocks[] = ['id' => $id, 'source' => $node->getLiteral(), 'language' => $language, 'page' => $source, 'ordinal' => $ordinal];
                $nodes[$id] = $node;
            }
        }
        if ($blocks === []) {
            return;
        }
        $context = $blocks[0]['page'] . ', block ' . $blocks[0]['ordinal'];
        try {
            $results = $renderBlocks($blocks, $staging);
            if (count($results) !== count($blocks) || array_diff_key($results, $nodes) !== []) {
                throw new RuntimeException('Block renderer returned missing or unknown block results.');
            }
            foreach ($blocks as $block) {
                if (!isset($results[$block['id']])) {
                    throw new RuntimeException($block['page'] . ', block ' . $block['ordinal'] . ': Block renderer omitted rendered HTML.');
                }
                $nodes[$block['id']]->data->set('website/html', $results[$block['id']]);
            }
        } catch (Throwable $error) {
            throw new RuntimeException($context . ': ' . $error->getMessage(), 0, $error);
        }
    }

    private function discover(): void
    {
        $book = $this->checkedSource($this->root . '/docs-book');
        $parser = new MarkdownParser($this->environment);
        $routes = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($book, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $absolute = $this->checkedSource($file->getPathname());
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            $source = substr($file->getPathname(), strlen($book) + 1);
            $url = $this->pageUrl($source);
            if (isset($routes[$url])) {
                throw new RuntimeException('Duplicate public page URL: ' . $url);
            }
            $routes[$url] = true;
            $document = $parser->parse((string) file_get_contents($absolute));
            $headings = $this->headings($document);
            $guide = str_starts_with($url, '/docs/operator-guide/') ? 'operator' : (str_starts_with($url, '/docs/developer-guide/') ? 'developer' : null);
            $this->documents[$source] = $document;
            $this->pages[$source] = ['source' => $source, 'url' => $url, 'title' => $headings[0]['title'] ?? basename($source, '.md'), 'guide' => $guide, 'html' => '', 'headings' => $headings];
        }
        ksort($this->pages);
    }

    /** @return list<array{id:string,title:string,level:int}> */
    private function headings(Document $document): array
    {
        $result = [];
        foreach ($document->iterator() as $node) {
            if (!$node instanceof Heading) {
                continue;
            }
            $title = StringContainerHelper::getChildText($node, [RawMarkupContainerInterface::class]);
            $slug = (string) $node->data->get("attributes/id");
            $result[] = ['id' => $slug, 'title' => $title, 'level' => $node->getLevel()];
        }
        return $result;
    }

    private function pageUrl(string $source): string
    {
        $path = str_replace(['part-1-operator-guide', 'part-2-developer-guide'], ['operator-guide', 'developer-guide'], $source);
        $path = preg_replace('~(?:^|/)README\.md$~', '', $path) ?? $path;
        $path = preg_replace('~\.md$~i', '', $path) ?? $path;
        return '/docs/' . ($path === '' ? '' : trim($path, '/') . '/');
    }

    private function transformLinks(): void
    {
        $errors = [];
        foreach ($this->documents as $source => $document) {
            foreach ($document->iterator() as $node) {
                if ($node instanceof HeadingPermalink) {
                    $node->data->set('attributes/data-pagefind-ignore', 'all');
                }
                if ($node instanceof Link || $node instanceof Image) {
                    try {
                        $node->setUrl($this->resolveLink($source, $node->getUrl(), $node instanceof Image));
                    } catch (RuntimeException $error) {
                        $errors[] = $error->getMessage();
                    }
                }
            }
        }
        if ($errors !== []) {
            throw new RuntimeException(implode("\n", array_unique($errors)));
        }
    }

    private function resolveLink(string $source, string $url, bool $image): string
    {
        if (preg_match('~^(https?://|mailto:)~i', $url)) {
            return $url;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) || str_starts_with($url, '//')) {
            throw new RuntimeException('Unsafe link in ' . $source . ': ' . $url);
        }
        if (str_starts_with($url, '/')) {
            throw new RuntimeException('Instance-local or unmapped absolute link in ' . $source . ': ' . $url);
        }
        [$path, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        if (str_contains($path, '?')) {
            throw new RuntimeException('Local query link is not supported in ' . $source . ': ' . $url);
        }
        $absolute = $path === '' ? $this->root . '/docs-book/' . $source : Paths::absolutePath($this->root . '/docs-book/' . dirname($source) . '/' . rawurldecode($path));
        $absolute = $this->checkedSource($absolute);
        if (is_dir($absolute) && is_file($absolute . '/README.md') && str_starts_with($absolute, $this->root . '/docs-book')) {
            $absolute .= '/README.md';
        }
        $relative = substr($absolute, strlen($this->root . '/docs-book/'));
        if (isset($this->pages[$relative])) {
            $page = $this->pages[$relative];
            if ($fragment !== null && $fragment !== '' && !in_array(rawurldecode($fragment), array_column($page['headings'], 'id'), true)) {
                throw new RuntimeException('Missing fragment in ' . $source . ': ' . $url);
            }
            return $page['url'] . ($fragment !== null ? '#' . $fragment : '');
        }
        if (str_starts_with($absolute, $this->root . '/docs-book/') && $this->isAsset($absolute)) {
            $assetUrl = '/assets/manual/' . substr($absolute, strlen($this->root . '/docs-book/'));
            $this->assets[$assetUrl] = $absolute;
            return $assetUrl . ($fragment !== null ? '#' . $fragment : '');
        }
        if ($image) {
            throw new RuntimeException('Manual image must be a supported book asset: ' . $source . ': ' . $url);
        }
        $browser = preg_replace('~/(tree|blob)/~', is_dir($absolute) ? '/tree/' : '/blob/', $this->revisionUrl);
        return $browser . '/' . implode('/', array_map(rawurlencode(...), explode('/', substr($absolute, strlen($this->root) + 1)))) . ($fragment !== null ? '#' . $fragment : '');
    }

    private function isAsset(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'avif', 'pdf'], true);
    }

    private function buildNavigation(): void
    {
        foreach (['operator' => 'part-1-operator-guide/README.md', 'developer' => 'part-2-developer-guide/README.md'] as $guide => $index) {
            if (!isset($this->documents[$index])) {
                throw new RuntimeException('Missing guide index: ' . $index);
            }
            $seen = [];
            $rootPage = $this->pages[$index];
            $this->navigation[$guide][] = ['url' => $rootPage['url'], 'title' => $rootPage['title']];
            $seen[$rootPage['url']] = true;
            foreach ($this->documents[$index]->iterator() as $node) {
                if (!$node instanceof Link) {
                    continue;
                }
                $url = explode('#', $node->getUrl(), 2)[0];
                foreach ($this->pages as $page) {
                    if ($page['url'] === $url && $page['guide'] === $guide && !isset($seen[$url])) {
                        $this->navigation[$guide][] = ['url' => $url, 'title' => $page['title']];
                        $seen[$url] = true;
                    }
                }
            }
        }
    }

    /** @param array<string,mixed> $page
     * @param array<string,mixed> $data
     */
    private function template(string $name, array $page, array $data = []): string
    {
        $navigation = $this->navigation;
        $site = ['repositoryUrl' => 'https://github.com/baander-app/baander', 'revisionUrl' => $this->revisionUrl];
        $template = $this->checkedSource($this->root . '/ui/site/templates/' . $name . '.php');
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($template, true);
        }
        ob_start();
        try {
            include $template;
            return (string) ob_get_clean();
        } catch (Throwable $error) {
            ob_end_clean();
            throw $error;
        }
    }

    private function checkedSource(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved === false || ($resolved !== $this->root && !str_starts_with($resolved, $this->root . '/'))) {
            throw new RuntimeException('Missing or escaping repository target: ' . $path);
        }
        return $resolved;
    }

    private function checkOutput(): void
    {
        foreach (['var', 'var/website'] as $path) {
            $absolute = $this->root . '/' . $path;
            if (is_link($absolute) || (file_exists($absolute) && !is_dir($absolute))) {
                throw new RuntimeException('Website output must be an owned directory: ' . $absolute);
            }
        }
        if (is_dir($this->root . '/var/website')) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root . '/var/website', RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
                if ($file->isLink()) {
                    throw new RuntimeException('Website output contains a symlink: ' . $file->getPathname());
                }
            }
        }
        if (str_starts_with($this->checkedSource($this->root . '/docs-book'), $this->root . '/var/')) {
            throw new RuntimeException('Website source and output overlap.');
        }
    }

    private function copySiteAssets(string $staging): void
    {
        $directory = $this->root . '/ui/site/assets';
        if (!is_dir($directory)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $source = $this->checkedSource($file->getPathname());
            if ($file->isFile()) {
                $this->write($staging . '/assets/site/' . substr($file->getPathname(), strlen($directory) + 1), (string) file_get_contents($source));
            }
        }
    }

    public function validateArtifact(string $directory): void
    {
        $documents = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'html') {
                continue;
            }
            $dom = new DOMDocument();
            $prior = libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="UTF-8">' . file_get_contents($file->getPathname()));
            libxml_clear_errors();
            libxml_use_internal_errors($prior);
            $documents[$file->getPathname()] = $dom;
        }
        foreach ($documents as $source => $dom) {
            foreach ($dom->getElementsByTagName('*') as $node) {
                foreach (['href', 'src'] as $attribute) {
                    if (!$node->hasAttribute($attribute)) {
                        continue;
                    }
                    $this->validateArtifactLink($node->getAttribute($attribute), $source, $directory, $documents);
                }
            }
        }
    }

    /** @param array<string,DOMDocument> $documents */
    private function validateArtifactLink(string $url, string $source, string $directory, array $documents): void
    {
        if (preg_match('~^(https?://|mailto:)~i', $url)) {
            return;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) || str_starts_with($url, '//')) {
            throw new RuntimeException('Unsafe artifact link: ' . $url);
        }
        [$path, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        $path = explode('?', $path, 2)[0];
        $target = $path === '' ? $source : Paths::absolutePath((str_starts_with($path, '/') ? $directory : dirname($source)) . '/' . rawurldecode($path));
        if (is_dir($target)) {
            $target = rtrim($target, '/') . '/index.html';
        }
        if (!str_starts_with($target, $directory . '/') || !is_file($target)) {
            throw new RuntimeException('Missing artifact target in ' . substr($source, strlen($directory)) . ': ' . $url);
        }
        if ($fragment !== null && $fragment !== '' && isset($documents[$target])) {
            foreach ($documents[$target]->getElementsByTagName('*') as $candidate) {
                if ($candidate->getAttribute('id') === rawurldecode($fragment)) {
                    return;
                }
            }
            throw new RuntimeException('Missing artifact fragment: ' . $url);
        }
    }

    private function write(string $path, string $content): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Cannot write website artifact: ' . $path);
        }
    }

    private function promote(string $staging): void
    {
        $output = $this->root . '/var/website';
        $backup = $staging . '-previous';
        if (is_dir($output) && !rename($output, $backup)) {
            throw new RuntimeException('Cannot retain previous website artifact.');
        }
        if (!rename($staging, $output)) {
            if (is_dir($backup)) {
                rename($backup, $output);
            }
            throw new RuntimeException('Cannot publish website artifact.');
        }
        $this->remove($backup);
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
