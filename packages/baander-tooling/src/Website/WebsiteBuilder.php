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

/**
 * A public page names its template and data. Its trail lists the ancestors below the homepage. Its
 * references are what it takes from the manual: links to manual pages, and blocks borrowed from
 * them, each placed as `html` at the path `into` of the data; `file` is the repository file that
 * states the references and is named when one does not resolve. A page that names `markdown`, a
 * repository Markdown file, is rendered from it: the file's title, its only level-1 heading and its
 * first block, reaches the template as `heading`, the rest as `html`, and links elsewhere in the site
 * to the file open the page.
 *
 * @phpstan-type ManualBlockReference array{manual:string,contains:string,into:list<int|string>}
 * @phpstan-type PublicPage array{template:string,url:string,title:string,description:string,stylesheet?:string,script?:string,sources?:list<string>,markdown?:string,trail?:list<array{name:string,url:string}>,references?:array{file:string,links:list<string>,blocks:list<ManualBlockReference>},data:array<string,mixed>}
 */
final class WebsiteBuilder
{
    private readonly Environment $environment;
    private readonly string $root;
    /** @var array<string,array{source:string,url:string,title:string,guide:?string,html:string,headings:list<array{id:string,title:string,level:int}>}> */
    private array $pages = [];
    /** @var array<string,Document> */
    private array $documents = [];
    /** @var array<string,array{url:string,document:Document,headings:list<array{id:string,title:string,level:int}>}> the Markdown files public pages are rendered from, by repository path */
    private array $references = [];
    /** @var array<string,string> */
    private array $assets = [];
    /** @var array<string,array{width:int,height:int}> the dimensions of each application picture, by name */
    private array $screenshots = [];
    /** The Mermaid statements the diagram renderer takes a figure's caption and alternative text from. */
    private const array DIAGRAM_ACCESSIBILITY = [
        'accTitle' => '/^\s*accTitle\s*:\s*.+$/m',
        'accDescr' => '/^\s*accDescr\s*(?::\s*.+$|\{[\s\S]*?\})/m',
    ];
    /** The public origin named by canonical links and the sitemap. */
    public const string ORIGIN = 'https://baander.app';
    /** Repository paths whose changes update the homepage's last-modified date. */
    private const array HOME_SOURCES = ['ui/site/templates/home.php', 'ui/site/assets/screenshots'];
    /** Each guide's breadcrumb, shared by the page header and the structured data. */
    private const array GUIDES = [
        'operator' => ['name' => 'Operator manual', 'url' => '/docs/operator-guide/'],
        'developer' => ['name' => 'Developer guide', 'url' => '/docs/developer-guide/'],
    ];
    /** Chapter indexes whose linked pages fold out under the chapter in the manual navigation. */
    private const array FOLD_OUT_CHAPTERS = [
        'part-1-operator-guide/getting-started/README.md',
        'part-1-operator-guide/configuration/README.md',
        'part-1-operator-guide/workers/README.md',
        'part-1-operator-guide/media/README.md',
        'part-1-operator-guide/administration/README.md',
        'part-1-operator-guide/operations/README.md',
        'part-1-operator-guide/maintenance/README.md',
        'part-1-operator-guide/commands/README.md',
        'part-2-developer-guide/getting-started/README.md',
        'part-2-developer-guide/architecture/README.md',
        'part-2-developer-guide/development/README.md',
        'part-2-developer-guide/testing/README.md',
        'part-2-developer-guide/contexts/README.md',
        'part-2-developer-guide/contributing/README.md',
    ];
    /** @var array<string,list<array{url:string,title:string,children?:list<array{url:string,title:string,group?:string}>}>> */
    private array $navigation = ['operator' => [], 'developer' => []];

    /** @param ?SiteMenu $menu the top menu every page's header and footer render; templates that render neither may go without */
    public function __construct(string $root, private readonly string $revisionUrl, private readonly ?SiteMenu $menu = null)
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
     * @param list<PublicPage> $publicPages
     * @param ?SourceHistory $history commit dates for the sitemap's last-modified values; without it they are omitted
     * @return array<string,array{source:string,url:string,title:string,guide:?string,html:string,headings:list<array{id:string,title:string,level:int}>}>
     */
    public function build(?Closure $afterRender = null, ?Closure $renderBlocks = null, array $publicPages = [], ?SourceHistory $history = null): array
    {
        $this->pages = $this->documents = $this->references = $this->assets = [];
        $this->navigation = ['operator' => [], 'developer' => []];
        $this->checkOutput();
        $this->discover();
        $this->discoverReferences($publicPages);
        $this->transformLinks();
        $this->buildNavigation();
        $borrowed = $this->manualReferences($publicPages);
        $this->screenshots = Screenshots::pairs($this->root);
        $staging = $this->root . '/var/website-build-' . bin2hex(random_bytes(8));
        mkdir($staging, 0755, true);
        try {
            $this->renderBlocks($staging, $renderBlocks);
            $renderer = new HtmlRenderer($this->environment);
            $chapters = $this->foldOutParents();
            foreach ($this->pages as $source => &$page) {
                $page['html'] = (string) $renderer->renderDocument($this->documents[$source]);
                $meta = $this->manualMetadata($source, $page, $chapters, $history);
                $this->write($staging . $page['url'] . 'index.html', $this->template('page', $page + ['meta' => $meta]));
            }
            unset($page);
            $this->write($staging . '/index.html', $this->template('home', [
                'source' => '', 'url' => '/', 'title' => 'Bånder', 'guide' => null, 'html' => '', 'headings' => [],
                'meta' => SearchMetadata::home(self::ORIGIN),
            ]));
            $this->write($staging . '/404.html', $this->template('404', [
                'source' => '', 'url' => '/404.html', 'title' => 'Page not found', 'guide' => null, 'html' => '', 'headings' => [],
                'meta' => SearchMetadata::notFound(self::ORIGIN),
            ]));
            $routes = array_fill_keys(array_column($this->pages, 'url'), true);
            $routes['/'] = $routes['/404.html'] = true;
            foreach ($publicPages as $position => $descriptor) {
                $url = $descriptor['url'];
                if (!preg_match('~^/(?:[a-z0-9]+(?:-[a-z0-9]+)*/)+$~', $url) || isset($routes[$url]) || str_starts_with($url, '/docs/')) {
                    throw new RuntimeException('Invalid or duplicate public page URL: ' . $url);
                }
                if (!preg_match('/^[a-z][a-z0-9-]*$/', $descriptor['template'])) {
                    throw new RuntimeException('Invalid public page template.');
                }
                $routes[$url] = true;
                $meta = SearchMetadata::page(
                    origin: self::ORIGIN,
                    url: $url,
                    heading: $descriptor['title'],
                    description: $descriptor['description'],
                    trail: $descriptor['trail'] ?? [],
                    lastModified: $history?->lastModified($descriptor['sources'] ?? ['ui/site/templates/' . $descriptor['template'] . '.php']),
                    schemaType: 'WebPage',
                );
                $data = $descriptor['data'];
                foreach ($borrowed[$position] ?? [] as ['into' => $into, 'block' => $block]) {
                    $data = self::withValue($data, [...$into, 'html'], $renderer->renderNodes([$block]));
                }
                $page = $this->referenceContent($renderer, $descriptor) + $descriptor + ['source' => '', 'guide' => null, 'html' => '', 'headings' => [], 'meta' => $meta];
                $this->write($staging . $url . 'index.html', $this->template($descriptor['template'], $page, $data));
            }
            $this->write($staging . '/sitemap.xml', $this->sitemap($publicPages, $history));
            $this->write($staging . '/robots.txt', Sitemap::robots(self::ORIGIN));
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

    /**
     * Lists the homepage, every manual page and every public page; the 404 page is never listed.
     * Each entry's last modification is the newest commit touching the sources it renders.
     *
     * @param list<array{template:string,url:string,sources?:list<string>}> $publicPages
     */
    private function sitemap(array $publicPages, ?SourceHistory $history): string
    {
        $entry = static fn(string $url, array $sources): array => [
            'url' => $url,
            'lastModified' => $history?->lastModified($sources),
        ];

        return [
            $entry('/', self::HOME_SOURCES),
            ...array_map(
                static fn(string $source, array $page): array => $entry($page['url'], ['docs-book/' . $source]),
                array_keys($this->pages),
                $this->pages,
            ),
            ...array_map(
                static fn(array $page): array => $entry(
                    $page['url'],
                    $page['sources'] ?? ['ui/site/templates/' . $page['template'] . '.php'],
                ),
                $publicPages,
            ),
        ] |> (static fn(array $entries): string => Sitemap::xml(self::ORIGIN, $entries));
    }

    /** @param ?Closure(list<array{id:string,source:string,language:string,page:string,ordinal:int}>,string):array<string,string> $renderBlocks */
    private function renderBlocks(string $staging, ?Closure $renderBlocks): void
    {
        $blocks = [];
        $nodes = [];
        foreach ($this->blockSources() as $source => $document) {
            $ordinal = 0;
            foreach ($document->iterator() as $node) {
                if (!$node instanceof FencedCode) {
                    continue;
                }
                ++$ordinal;
                $language = self::language($node);
                $id = 'block-' . substr(hash('sha256', $source . ':' . $ordinal), 0, 16);
                if ($language === 'php') {
                    try {
                        $html = new CodeHighlighter()->highlight($node->getLiteral());
                    } catch (\Throwable $error) {
                        fwrite(STDERR, $source . ', block ' . $ordinal . ': PHP highlighting failed; using escaped source: ' . $error->getMessage() . "\n");
                        $html = htmlspecialchars($node->getLiteral(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    }
                    $node->data->set('website/html', CodeBlock::html('php', $html));
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

    /**
     * Every parsed document by the name its blocks are reported under: a manual page by its path in
     * the book, a reference source by its repository path.
     *
     * @return \Generator<string,Document>
     */
    private function blockSources(): \Generator
    {
        yield from $this->documents;
        foreach ($this->references as $source => $reference) {
            yield $source => $reference['document'];
        }
    }

    private static function language(FencedCode $block): string
    {
        $language = strtolower($block->getInfoWords()[0] ?? '');

        return str_starts_with($language, 'language-') ? substr($language, strlen('language-')) : $language;
    }

    /**
     * Checks what the public pages take from the manual against the discovered manual pages and
     * reports every failure at once, each under the file that states the reference.
     *
     * @param list<PublicPage> $publicPages
     * @return array<int, list<array{into: list<int|string>, block: FencedCode}>> the blocks each public page borrows, by the page's position
     */
    private function manualReferences(array $publicPages): array
    {
        $errors = [];
        $borrowed = [];
        foreach ($publicPages as $position => $descriptor) {
            $references = $descriptor['references'] ?? ['file' => '', 'links' => [], 'blocks' => []];
            foreach ($references['links'] as $href) {
                $problem = $this->manualLinkProblem($href);
                if ($problem !== null) {
                    $errors[] = $references['file'] . ': ' . $problem;
                }
            }
            foreach ($references['blocks'] as $reference) {
                try {
                    $borrowed[$position][] = ['into' => $reference['into'], 'block' => $this->manualBlock($reference)];
                } catch (RuntimeException $error) {
                    $errors[] = $references['file'] . ': ' . $error->getMessage();
                }
            }
        }
        if ($errors !== []) {
            throw new RuntimeException(implode("\n", array_unique($errors)));
        }

        return $borrowed;
    }

    private function manualLinkProblem(string $href): ?string
    {
        [$url, $fragment] = array_pad(explode('#', $href, 2), 2, '');
        $page = array_find($this->pages, static fn(array $page): bool => $page['url'] === $url);

        return match (true) {
            $page === null => 'manual link ' . $href . ' names no manual page',
            $fragment !== '' && !in_array(rawurldecode($fragment), array_column($page['headings'], 'id'), true)
                => 'manual link ' . $href . ' names a heading its page does not have',
            default => null,
        };
    }

    /**
     * The one block of a manual page whose source contains the reference's text. A diagram must
     * state its title and description, which the page that borrows it shows as caption and
     * alternative text.
     *
     * @param ManualBlockReference $reference
     */
    private function manualBlock(array $reference): FencedCode
    {
        ['manual' => $source, 'contains' => $text] = $reference;
        $document = $this->documents[$source] ?? throw new RuntimeException('manual block reference names no manual page: ' . $source);
        $matches = [];
        foreach ($document->iterator() as $node) {
            if ($node instanceof FencedCode && str_contains($node->getLiteral(), $text)) {
                $matches[] = $node;
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException($matches === []
                ? 'no block of ' . $source . ' contains "' . $text . '"'
                : count($matches) . ' blocks of ' . $source . ' contain "' . $text . '"; a manual block reference matches exactly one');
        }
        $missing = self::language($matches[0]) === 'mermaid'
            ? array_filter(self::DIAGRAM_ACCESSIBILITY, static fn(string $statement): bool => preg_match($statement, $matches[0]->getLiteral()) !== 1)
            : [];
        if ($missing !== []) {
            throw new RuntimeException('the diagram of ' . $source . ' that contains "' . $text . '" has no ' . implode(' and no ', array_keys($missing)));
        }

        return $matches[0];
    }

    /**
     * @param array<mixed> $data
     * @param non-empty-list<int|string> $path
     * @return array<mixed> the data with the value set at the path
     */
    private static function withValue(array $data, array $path, string $value): array
    {
        $key = array_shift($path);
        $data[$key] = $path === [] ? $value : self::withValue($data[$key], $path, $value);

        return $data;
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

    /**
     * Parses the Markdown file each public page names. A file must exist, hold more than white space
     * and open with its title, its only level-1 heading; every failure is reported at once.
     *
     * @param list<PublicPage> $publicPages
     */
    private function discoverReferences(array $publicPages): void
    {
        $parser = new MarkdownParser($this->environment);
        $errors = [];
        foreach ($publicPages as $descriptor) {
            $source = $descriptor['markdown'] ?? null;
            if ($source === null) {
                continue;
            }
            try {
                $absolute = $this->checkedSource($this->root . '/' . $source);
            } catch (RuntimeException) {
                $errors[] = 'Missing or escaping reference source for ' . $descriptor['url'] . ': ' . $source;
                continue;
            }
            $markdown = (string) file_get_contents($absolute);
            if (trim($markdown) === '') {
                $errors[] = $source . ': the reference source is empty';
                continue;
            }
            $document = $parser->parse($markdown);
            $headings = $this->headings($document);
            $first = $document->firstChild();
            if (!$first instanceof Heading || $first->getLevel() !== 1 || count(array_filter($headings, static fn(array $heading): bool => $heading['level'] === 1)) !== 1) {
                $errors[] = $source . ': a reference source opens with its title, its only level-1 heading';
                continue;
            }
            $this->references[$source] = ['url' => $descriptor['url'], 'document' => $document, 'headings' => $headings];
        }
        if ($errors !== []) {
            throw new RuntimeException(implode("\n", $errors));
        }
    }

    /**
     * The rendered title, body and headings of a page built from a Markdown file; nothing for any
     * other page.
     *
     * @param PublicPage $descriptor
     * @return array{heading?:string,html?:string,headings?:list<array{id:string,title:string,level:int}>,source?:string}
     */
    private function referenceContent(HtmlRenderer $renderer, array $descriptor): array
    {
        $source = $descriptor['markdown'] ?? null;
        if ($source === null) {
            return [];
        }
        ['document' => $document, 'headings' => $headings] = $this->references[$source];
        $title = $document->firstChild();
        $body = array_slice([...$document->children()], 1);

        return [
            'heading' => (string) $renderer->renderNodes([$title]),
            'html' => (string) $renderer->renderNodes($body),
            'headings' => $headings,
            'source' => $source,
        ];
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
            $errors = [...$errors, ...$this->transformDocumentLinks('docs-book/' . $source, $document)];
        }
        foreach ($this->references as $source => $reference) {
            $errors = [...$errors, ...$this->transformDocumentLinks($source, $reference['document'])];
        }
        if ($errors !== []) {
            throw new RuntimeException(implode("\n", array_unique($errors)));
        }
    }

    /**
     * @param string $source the document's repository path, which its relative links start from
     * @return list<string> the links that do not resolve
     */
    private function transformDocumentLinks(string $source, Document $document): array
    {
        $errors = [];
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

        return $errors;
    }

    /**
     * Resolves a link from the directory of the repository file at `$source`. A manual page or a
     * file a public page is rendered from opens that page, with its fragment checked against the
     * page's headings; a book picture is copied into the site; any other repository path opens in
     * the source repository.
     */
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
        $suffix = $fragment !== null ? '#' . $fragment : '';
        try {
            $absolute = $this->checkedSource($path === '' ? $this->root . '/' . $source : Paths::absolutePath($this->root . '/' . dirname($source) . '/' . rawurldecode($path)));
        } catch (RuntimeException) {
            throw new RuntimeException('Missing or escaping repository target in ' . $source . ': ' . $url);
        }
        $book = $this->root . '/docs-book';
        $inBook = $absolute === $book || str_starts_with($absolute, $book . '/');
        if ($inBook && is_dir($absolute) && is_file($absolute . '/README.md')) {
            $absolute .= '/README.md';
        }
        $page = $inBook ? $this->pages[substr($absolute, strlen($book) + 1)] ?? null : null;
        $page ??= $this->references[substr($absolute, strlen($this->root) + 1)] ?? null;
        if ($page !== null) {
            if ($fragment !== null && $fragment !== '' && !in_array(rawurldecode($fragment), array_column($page['headings'], 'id'), true)) {
                throw new RuntimeException('Missing fragment in ' . $source . ': ' . $url);
            }
            return $page['url'] . $suffix;
        }
        if ($inBook && $this->isAsset($absolute)) {
            $assetUrl = '/assets/manual/' . substr($absolute, strlen($book) + 1);
            $this->assets[$assetUrl] = $absolute;
            return $assetUrl . $suffix;
        }
        if ($image) {
            throw new RuntimeException('Manual image must be a supported book asset: ' . $source . ': ' . $url);
        }
        $browser = preg_replace('~/(tree|blob)/~', is_dir($absolute) ? '/tree/' : '/blob/', $this->revisionUrl);
        return $browser . '/' . implode('/', array_map(rawurlencode(...), explode('/', substr($absolute, strlen($this->root) + 1)))) . $suffix;
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
            $rootPage = $this->pages[$index];
            $seen = [$rootPage['url'] => true];
            $this->navigation[$guide][] = ['url' => $rootPage['url'], 'title' => $rootPage['title']];
            foreach ($this->linkedPages($index, $guide, $seen) as ['page' => $page]) {
                $entry = ['url' => $page['url'], 'title' => $page['title']];
                if (in_array($page['source'], self::FOLD_OUT_CHAPTERS, true)) {
                    $entry['children'] = $this->linkedPages($page['source'], $guide, $seen, dirname($page['source']))
                        |> (static fn(array $linked): array => array_map(self::foldOutEntry(...), $linked));
                }
                $this->navigation[$guide][] = $entry;
            }
        }
    }

    /**
     * Search metadata for a manual page: its guide and, inside a fold-out chapter, that chapter form
     * the trail; the opening paragraphs form the description.
     *
     * @param array{source:string,url:string,title:string,guide:?string} $page
     * @param array<string, array{name: string, url: string}> $chapters fold-out page URL => its chapter
     */
    private function manualMetadata(string $source, array $page, array $chapters, ?SourceHistory $history): SearchMetadata
    {
        $guide = $page['guide'] === null ? null : self::GUIDES[$page['guide']];
        $trail = $guide === null || $guide['url'] === $page['url'] ? [] : [$guide];
        if (isset($chapters[$page['url']])) {
            $trail[] = $chapters[$page['url']];
        }
        $description = SearchMetadata::description($this->documents[$source])
            ?? $page['title'] . ' in the ' . SearchMetadata::SITE_NAME . ' ' . ($guide['name'] ?? 'manual') . '.';

        return SearchMetadata::page(
            origin: self::ORIGIN,
            url: $page['url'],
            heading: $page['title'],
            description: $description,
            trail: $trail,
            lastModified: $history?->lastModified(['docs-book/' . $source]),
        );
    }

    /** @return array<string, array{name: string, url: string}> fold-out page URL => the chapter that lists it */
    private function foldOutParents(): array
    {
        $parents = [];
        foreach (array_merge(...array_values($this->navigation)) as $chapter) {
            foreach ($chapter['children'] ?? [] as $child) {
                $parents[$child['url']] = ['name' => $chapter['title'], 'url' => $chapter['url']];
            }
        }

        return $parents;
    }

    /**
     * A fold-out child keeps the chapter index's section heading as its group label.
     *
     * @param array{page: array{url: string, title: string}, group: ?string} $linked
     * @return array{url: string, title: string, group?: string}
     */
    private static function foldOutEntry(array $linked): array
    {
        $entry = ['url' => $linked['page']['url'], 'title' => $linked['page']['title']];

        return $linked['group'] === null ? $entry : $entry + ['group' => $linked['group']];
    }

    /**
     * Pages of one guide linked from a document, in link order, each listed once across the guide,
     * with the document's most recent second-level heading before each link.
     *
     * @param array<string,true> $seen
     * @return list<array{page:array{source:string,url:string,title:string,guide:?string,html:string,headings:list<array{id:string,title:string,level:int}>},group:?string}>
     */
    private function linkedPages(string $source, string $guide, array &$seen, ?string $directory = null): array
    {
        $linked = [];
        $group = null;
        foreach ($this->documents[$source]->iterator() as $node) {
            if ($node instanceof Heading && $node->getLevel() === 2) {
                $group = trim(StringContainerHelper::getChildText($node, [RawMarkupContainerInterface::class]));
                continue;
            }
            if (!$node instanceof Link) {
                continue;
            }
            $url = explode('#', $node->getUrl(), 2)[0];
            foreach ($this->pages as $page) {
                if ($page['url'] !== $url || $page['guide'] !== $guide || isset($seen[$url])) {
                    continue;
                }
                $chapterIndex = dirname($page['source']) . '/README.md';
                if ($directory !== null && dirname($page['source']) !== $directory) {
                    continue;
                }
                if ($directory === null && $page['source'] !== $chapterIndex && in_array($chapterIndex, self::FOLD_OUT_CHAPTERS, true)) {
                    continue;
                }
                $linked[] = ['page' => $page, 'group' => $group];
                $seen[$url] = true;
            }
        }
        return $linked;
    }

    /** @param array<string,mixed> $page
     * @param array<string,mixed> $data
     */
    private function template(string $name, array $page, array $data = []): string
    {
        $navigation = $this->navigation;
        $site = [
            'origin' => self::ORIGIN,
            'repositoryUrl' => 'https://github.com/baander-app/baander',
            'revisionUrl' => $this->revisionUrl,
            'screenshots' => $this->screenshots,
            'menu' => $this->menu?->forPage((string) $page['url']),
        ];
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
