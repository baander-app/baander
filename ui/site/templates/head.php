<?php declare(strict_types=1);
use Baander\Tooling\Website\SearchMetadata;

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
/** @var SearchMetadata $meta */
$meta = $page['meta'];
[$imageWidth, $imageHeight] = SearchMetadata::SOCIAL_IMAGE_SIZE;
$image = $site['origin'] . SearchMetadata::SOCIAL_IMAGE;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark light">
  <title><?= $escape($meta->title) ?></title>
  <meta name="description" content="<?= $escape($meta->description) ?>">
  <?php if ($meta->indexable): ?>
    <link rel="canonical" href="<?= $escape($meta->url) ?>">
  <?php else: ?>
    <meta name="robots" content="noindex">
  <?php endif; ?>
  <meta property="og:site_name" content="<?= $escape(SearchMetadata::SITE_NAME) ?>">
  <meta property="og:type" content="<?= $escape($meta->type) ?>">
  <meta property="og:title" content="<?= $escape($meta->title) ?>">
  <meta property="og:description" content="<?= $escape($meta->description) ?>">
  <meta property="og:url" content="<?= $escape($meta->url) ?>">
  <meta property="og:locale" content="en_US">
  <meta property="og:image" content="<?= $escape($image) ?>">
  <meta property="og:image:width" content="<?= $imageWidth ?>">
  <meta property="og:image:height" content="<?= $imageHeight ?>">
  <meta property="og:image:alt" content="Bånder's song browser beside the headline: Your collection. Ready to play.">
  <meta name="twitter:card" content="summary_large_image">
  <?php if ($meta->structuredData !== null): ?>
    <script type="application/ld+json"><?= $meta->structuredData ?></script>
  <?php endif; ?>
  <link rel="icon" type="image/svg+xml" href="/assets/site/logo.svg">
  <link rel="stylesheet" href="/assets/site/theme.css">
  <link rel="stylesheet" href="/assets/site/site.css">
  <?php if (isset($page['stylesheet'])): ?>
    <link rel="stylesheet" href="/assets/site/<?= $escape($page['stylesheet']) ?>">
  <?php endif; ?>
  <?php if (str_starts_with($page['url'], '/docs/')): ?>
    <link rel="stylesheet" href="/assets/site/search.css">
    <script type="module" src="/assets/site/search.js"></script>
    <script src="/assets/site/manual-navigation.js" defer></script>
    <script src="/assets/site/diagram-explore.js" defer></script>
  <?php endif; ?>
  <script src="/assets/site/appearance.js"></script>
  <?php if (isset($page['script'])): ?>
    <script src="/assets/site/<?= $escape($page['script']) ?>" defer></script>
  <?php endif; ?>
  <?php if ($page['url'] === '/'): ?>
    <script src="/assets/site/carousel.js" defer></script>
  <?php endif; ?>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
