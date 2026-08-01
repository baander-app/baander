<?php

declare(strict_types=1);

namespace App\Command\DocGenerator;

use App\Command\DocGenerator\Model\BoundedContext;

final class HtmlRenderer
{
    private PhpDocLinkBuilder $linkBuilder;
    private string $outputDir = '';

    public function __construct()
    {
        $this->linkBuilder = new PhpDocLinkBuilder();
    }

    /**
     * @param list<BoundedContext> $contexts
     */
    public function renderAll(array $contexts, string $outputDir, string $phpdocDir): void
    {
        $this->outputDir = realpath($outputDir) ?: $outputDir;
        $this->renderApiReference($contexts, $outputDir, $phpdocDir);
        $this->renderDomainModels($contexts, $outputDir, $phpdocDir);
        $this->renderArchitectureGuide($contexts, $outputDir, $phpdocDir);

        if (!is_dir($outputDir . '/contexts')) {
            mkdir($outputDir . '/contexts', 0755, true);
        }

        foreach ($contexts as $context) {
            $this->renderContextOverview($context, $outputDir, $phpdocDir);
        }
    }

    /**
     * @param list<BoundedContext> $contexts
     * @param list<string> $convertedPages
     */
    public function writeIndex(array $contexts, string $outputDir, string $phpdocDir, array $convertedPages): void
    {
        $phpdocLink = $this->linkBuilder->indexLink('', $phpdocDir);

        $contextLinks = '';
        foreach ($contexts as $context) {
            $name = $this->escape($context->name);
            $slug = $this->contextSlug($context->name);
            $contextLinks .= '<li><a href="contexts/' . $slug . '.html">' . $name . '</a>';
            if ($context->description !== '') {
                $contextLinks .= ' — ' . $this->escape($context->description);
            }
            $contextLinks .= '</li>';
        }

        $guideLinks = '';
        $hasOperator = in_array('operator-guide/index.html', $convertedPages, true);
        $hasDeveloper = in_array('developer-guide/index.html', $convertedPages, true);

        if ($hasOperator) {
            $guideLinks .= '<li><a href="operator-guide/index.html">Operator\'s Guide</a> — Deploy, configure, and maintain Bånder</li>';
        }
        if ($hasDeveloper) {
            $guideLinks .= '<li><a href="developer-guide/index.html">Developer\'s Guide</a> — Architecture, coding, and contributing</li>';
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bånder Documentation</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<nav>
    <strong>Bånder Docs</strong>
    <a href="api-reference.html">API Reference</a>
    <a href="domain-models.html">Domain Models</a>
    <a href="architecture-guide.html">Architecture</a>
    <a href="{$phpdocLink}">API Docs (phpDocumentor)</a>
</nav>
<main>
<h1>Bånder Documentation</h1>

<h2>Guides</h2>
<ul>
{$guideLinks}
</ul>

<h2>Auto-Generated Reference</h2>
<ul>
    <li><a href="api-reference.html">API Reference</a> — All routes grouped by bounded context</li>
    <li><a href="domain-models.html">Domain Model Reference</a> — Aggregate roots and value objects</li>
    <li><a href="architecture-guide.html">Architecture Guide</a> — DDD layers, CQRS flow, and patterns</li>
</ul>

<h2>Bounded Contexts</h2>
<ul>
{$contextLinks}
</ul>

<h2>External Resources</h2>
<ul>
    <li><a href="{$phpdocLink}">Full API Documentation</a> (phpDocumentor)</li>
</ul>
</main>
</body>
</html>
HTML;

        file_put_contents($outputDir . '/index.html', $html);
    }

    public function writeCss(string $outputDir): void
    {
        $css = file_get_contents(__DIR__ . '/Resources/style.css');
        file_put_contents($outputDir . '/css/style.css', $css);
    }

    private function renderApiReference(array $contexts, string $outputDir, string $phpdocDir): void
    {
        $sections = '';
        foreach ($contexts as $context) {
            if (empty($context->routes)) {
                continue;
            }

            $name = $this->escape($context->name);
            $rows = '';
            foreach ($context->routes as $route) {
                $controllerLink = $this->linkBuilder->classLink($route->controllerFqcn, '', $phpdocDir);
                $rows .= '<tr>'
                    . '<td><code>' . $this->escape($route->methods) . '</code></td>'
                    . '<td><code>' . $this->escape($route->path) . '</code></td>'
                    . '<td><a href="' . $controllerLink . '">' . $this->escape($route->methodName) . '</a></td>'
                    . '<td>' . $this->escape($route->description) . '</td>'
                    . '</tr>';
            }

            $sections .= "<h3>{$name}</h3><table><thead><tr><th>Method</th><th>Path</th><th>Action</th><th>Description</th></tr></thead><tbody>{$rows}</tbody></table>";
        }

        $this->writePage(
            $outputDir . '/api-reference.html',
            'API Reference',
            '<h1>API Reference</h1>' . $sections,
            '',
            $phpdocDir,
        );
    }

    private function renderDomainModels(array $contexts, string $outputDir, string $phpdocDir): void
    {
        $sections = '';
        foreach ($contexts as $context) {
            if (empty($context->aggregateRoots) && empty($context->valueObjects)) {
                continue;
            }

            $name = $this->escape($context->name);
            $rows = '';

            foreach ($context->aggregateRoots as $model) {
                $link = $this->linkBuilder->classLink($model->fqcn, '', $phpdocDir);
                $props = implode(', ', array_map($this->escape(...), $model->properties));
                $rows .= '<tr>'
                    . '<td><a href="' . $link . '">' . $this->escape($model->shortName) . '</a></td>'
                    . '<td>Aggregate Root</td>'
                    . '<td>' . $this->escape($model->description) . '</td>'
                    . '<td><code>' . $this->escape($props ?: '—') . '</code></td>'
                    . '</tr>';
            }

            foreach ($context->valueObjects as $model) {
                $link = $this->linkBuilder->classLink($model->fqcn, '', $phpdocDir);
                $type = $model->isEnum ? 'Enum' : 'Value Object';
                $rows .= '<tr>'
                    . '<td><a href="' . $link . '">' . $this->escape($model->shortName) . '</a></td>'
                    . '<td>' . $type . '</td>'
                    . '<td>' . $this->escape($model->description) . '</td>'
                    . '<td>—</td>'
                    . '</tr>';
            }

            $sections .= "<h3>{$name}</h3><table><thead><tr><th>Name</th><th>Type</th><th>Description</th><th>Properties</th></tr></thead><tbody>{$rows}</tbody></table>";
        }

        $this->writePage(
            $outputDir . '/domain-models.html',
            'Domain Model Reference',
            '<h1>Domain Model Reference</h1>' . $sections,
            '',
            $phpdocDir,
        );
    }

    private function renderArchitectureGuide(array $contexts, string $outputDir, string $phpdocDir): void
    {
        $cqrsSection = '';
        foreach ($contexts as $context) {
            if (empty($context->handlers)) {
                continue;
            }

            $name = $this->escape($context->name);
            $rows = '';
            foreach ($context->handlers as $handler) {
                $handlerLink = $this->linkBuilder->classLink($handler->handlerFqcn, '', $phpdocDir);
                $rows .= '<tr>'
                    . '<td><code>' . $this->escape($handler->commandShortName) . '</code></td>'
                    . '<td><a href="' . $handlerLink . '">' . $this->escape($handler->handlerShortName) . '</a></td>'
                    . '</tr>';
            }

            $cqrsSection .= "<h3>{$name}</h3><table><thead><tr><th>Command</th><th>Handler</th></tr></thead><tbody>{$rows}</tbody></table>";
        }

        $contextLayerSummary = '';
        foreach ($contexts as $context) {
            $name = $this->escape($context->name);
            $layers = implode(', ', array_map($this->escape(...), $context->layers));
            $classCount = count($context->classes);
            $contextLayerSummary .= '<tr><td>' . $name . '</td><td>' . $layers . '</td><td>' . $classCount . '</td></tr>';
        }

        $content = <<<HTML
<h1>Architecture Guide</h1>

<h2>Bounded Context Layer Structure</h2>
<p>Bånder follows Domain-Driven Design with strict bounded contexts. Each context contains up to four layers:</p>
<ul>
    <li><strong>Domain</strong> — Business rules, entities, value objects, repository interfaces</li>
    <li><strong>Application</strong> — Use cases, command/query handlers, port interfaces</li>
    <li><strong>Infrastructure</strong> — Doctrine repositories, external adapters, storage</li>
    <li><strong>Interface</strong> — Controllers, request DTOs, API resources</li>
</ul>

<table>
<thead><tr><th>Context</th><th>Layers</th><th>Classes</th></tr></thead>
<tbody>{$contextLayerSummary}</tbody>
</table>

<h2>CQRS Flow</h2>
<p>Commands and queries are dispatched via Symfony Messenger. Each command has a dedicated handler marked with <code>#[AsMessageHandler]</code>.</p>
{$cqrsSection}

<h2>Port Pattern</h2>
<p>Controllers depend on <strong>port interfaces</strong> defined in <code>Application/Port/</code>, not on repositories directly. This keeps the Interface layer decoupled from Infrastructure.</p>
HTML;

        $this->writePage(
            $outputDir . '/architecture-guide.html',
            'Architecture Guide',
            $content,
            '',
            $phpdocDir,
        );
    }

    private function renderContextOverview(BoundedContext $context, string $outputDir, string $phpdocDir): void
    {
        $slug = $this->contextSlug($context->name);
        $name = $this->escape($context->name);
        $filePath = $outputDir . '/contexts/' . $slug . '.html';

        $layerSections = [];
        $classesByLayer = [];
        foreach ($context->classes as $classInfo) {
            $classesByLayer[$classInfo->layer][] = $classInfo;
        }

        foreach ($classesByLayer as $layer => $classes) {
            $rows = '';
            foreach ($classes as $classInfo) {
                $link = $this->linkBuilder->classLink($classInfo->fqcn, 'contexts/' . $slug . '.html', $phpdocDir);
                $badges = '';
                if ($classInfo->isAggregateRoot) {
                    $badges .= ' <span class="badge aggregate">Aggregate Root</span>';
                }
                if ($classInfo->isValueObject) {
                    $badges .= ' <span class="badge value-object">Value Object</span>';
                }

                $rows .= '<tr>'
                    . '<td><a href="' . $link . '">' . $this->escape($classInfo->shortName) . '</a>' . $badges . '</td>'
                    . '<td>' . $this->escape($classInfo->description) . '</td>'
                    . '</tr>';
            }

            $layerSections[] = '<h3>' . $this->escape($layer) . '</h3>'
                . '<table><thead><tr><th>Class</th><th>Description</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        }

        $description = $context->description !== ''
            ? '<p>' . $this->escape($context->description) . '</p>'
            : '';

        $content = "<h1>{$name}</h1>{$description}" . implode("\n", $layerSections);

        $this->writePage($filePath, $name . ' Context', $content, 'contexts/' . $slug . '.html', $phpdocDir);
    }

    private function contextSlug(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
    }

    private function writePage(string $filePath, string $title, string $content, string $fromPage, string $phpdocDir, string $sidebar = ''): void
    {
        $resolvedPath = realpath(dirname($filePath)) . '/' . basename($filePath);
        $relative = substr($resolvedPath, strlen($this->outputDir) + 1);
        $dirDepth = substr_count($relative, '/');
        $prefix = str_repeat('../', $dirDepth);

        $phpdocLink = $this->linkBuilder->indexLink($fromPage, $phpdocDir);

        $sidebarHtml = $sidebar !== '' ? '<aside>' . $sidebar . '</aside>' : '';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$this->escape($title)} — Bånder Docs</title>
    <link rel="stylesheet" href="{$prefix}css/style.css">
</head>
<body>
<nav>
    <a href="{$prefix}index.html">Bånder Docs</a>
    <a href="{$prefix}api-reference.html">API Reference</a>
    <a href="{$prefix}domain-models.html">Domain Models</a>
    <a href="{$prefix}architecture-guide.html">Architecture</a>
    <a href="{$phpdocLink}">API Docs</a>
</nav>
{$sidebarHtml}
<main>
{$content}
</main>
</body>
</html>
HTML;

        file_put_contents($filePath, $html);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
