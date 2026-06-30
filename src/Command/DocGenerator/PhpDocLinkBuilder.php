<?php

declare(strict_types=1);

namespace App\Command\DocGenerator;

final class PhpDocLinkBuilder
{
    /**
     * Build a relative link to a phpDocumentor class page.
     * Class URLs use mixed case: App-Auth-Domain-Model-UserState.html
     */
    public function classLink(string $fqcn, string $fromPage, string $phpdocDir): string
    {
        $relativePath = $this->relativePathToPhpdoc($fromPage, $phpdocDir);
        $classSlug = str_replace('\\', '-', $fqcn);

        return $relativePath . 'classes/' . $classSlug . '.html';
    }

    /**
     * Build a relative link to a phpDocumentor namespace page.
     * Namespace URLs use lowercase: app-auth-domain-model.html
     */
    public function namespaceLink(string $namespace, string $fromPage, string $phpdocDir): string
    {
        $relativePath = $this->relativePathToPhpdoc($fromPage, $phpdocDir);
        $namespaceSlug = strtolower(str_replace('\\', '-', $namespace));

        return $relativePath . 'namespaces/' . $namespaceSlug . '.html';
    }

    /**
     * Build a relative link to the phpDocumentor index page.
     */
    public function indexLink(string $fromPage, string $phpdocDir): string
    {
        $relativePath = $this->relativePathToPhpdoc($fromPage, $phpdocDir);

        return $relativePath . 'index.html';
    }

    private function relativePathToPhpdoc(string $fromPage, string $phpdocDir): string
    {
        $depth = substr_count($fromPage, '/');

        return str_repeat('../', $depth) . $phpdocDir . '/';
    }
}
