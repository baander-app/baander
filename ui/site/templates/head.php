<?php declare(strict_types=1);
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark light">
  <title><?= $escape($page['title'] === 'Bånder' ? 'Bånder — A home for your media' : $page['title'] . ' — Bånder') ?></title>
  <meta name="description" content="Browse and play your music and movies with Bånder, a media server you host. Read the installation and operator manuals.">
  <?php if ($page['url'] !== '/404.html'): ?>
    <link rel="canonical" href="https://baander.app<?= $escape($page['url']) ?>">
  <?php else: ?>
    <meta name="robots" content="noindex">
  <?php endif; ?>
  <link rel="icon" type="image/svg+xml" href="/assets/site/logo.svg">
  <link rel="stylesheet" href="/assets/site/theme.css">
  <link rel="stylesheet" href="/assets/site/site.css">
  <?php if (str_starts_with($page['url'], '/docs/')): ?>
    <link rel="stylesheet" href="/assets/site/search.css">
    <script type="module" src="/assets/site/search.js"></script>
  <?php endif; ?>
  <script src="/assets/site/appearance.js"></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
