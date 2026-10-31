<?php declare(strict_types=1);
include __DIR__ . '/head.php';
include __DIR__ . '/header.php';
?>
<main id="main" class="homepage">
  <section class="hero" aria-labelledby="intro-title">
    <div class="hero-copy">
      <p class="eyebrow">A home for your media</p>
      <h1 id="intro-title">Your collection.<br>Ready to play.</h1>
      <p class="hero-description">Browse your music and movies in Bånder.<br>A media server you run on your own hardware.</p>
      <div class="actions">
        <a class="button" href="/docs/operator-guide/getting-started/">Start hosting <span aria-hidden="true">→</span></a>
        <a class="text-action" href="#how-it-works">How Bånder works <span aria-hidden="true">↓</span></a>
      </div>
      <p class="release-note">Pre-release · Current installation builds from source</p>
    </div>
    <figure class="app-screenshot hero-screenshot">
      <img src="/assets/site/screenshots/music.png" width="1600" height="1000" alt="Bånder's music library in the browser, with album artwork and playback controls.">
      <figcaption>Music browsing and playback · Captured in Bånder</figcaption>
    </figure>
  </section>

  <section id="how-it-works" class="home-section operating-model" aria-labelledby="how-title">
    <div class="section-intro">
      <p class="eyebrow">From files to playback</p>
      <h2 id="how-title">Your files. A connected library.</h2>
      <p>You provide the media and run the server. Bånder brings your music and movies into a catalog you can browse and play in your web browser.</p>
      <a class="text-action" href="/docs/operator-guide/understanding-baander/">Understand your instance <span aria-hidden="true">→</span></a>
    </div>
    <ol class="process-list">
      <li><span class="step-number" aria-hidden="true">01</span><div><h3>Connect your media</h3><p>Add a library pointing to your media files. A scan discovers what's there; background work adds it to the catalog.</p></div></li>
      <li><span class="step-number" aria-hidden="true">02</span><div><h3>Browse your collection</h3><p>Explore music and movies in the browser. Optional providers can add metadata, artwork and lyrics.</p></div></li>
      <li><span class="step-number" aria-hidden="true">03</span><div><h3>Choose something to play</h3><p>Play from the original file, or use converted audio and video prepared by the worker deployment.</p></div></li>
    </ol>
  </section>

  <section class="home-section hosting" aria-labelledby="hosting-title">
    <div class="section-intro">
      <p class="eyebrow">Running Bånder</p>
      <h2 id="hosting-title">Know what you're hosting.</h2>
      <p>Bånder is pre-release software. The current installation builds from source and adapts a development-oriented Compose stack, with explicit network, storage and credential preparation.</p>
      <p>The web application uses PostgreSQL and Redis. Background work needs a separate control and execution deployment pair, shared media paths and output storage. Starting the web service alone does not start that pair.</p>
      <a class="button" href="/docs/operator-guide/getting-started/">Read the installation guide <span aria-hidden="true">→</span></a>
    </div>
    <div class="hosting-reference">
      <p class="eyebrow">Before your first scan</p>
      <a href="/docs/operator-guide/understanding-baander/"><span>The components</span><span aria-hidden="true">↗</span></a>
      <a href="/docs/operator-guide/storage-and-data/"><span>Storage and data</span><span aria-hidden="true">↗</span></a>
      <a href="/docs/operator-guide/background-work/"><span>Background work</span><span aria-hidden="true">↗</span></a>
      <p>Follow the guide's checkpoints to check your installation, catalog and playback.</p>
    </div>
  </section>

  <section class="home-section manual-intro" aria-labelledby="manual-title">
    <div class="section-intro">
      <p class="eyebrow">The Bånder manual</p>
      <h2 id="manual-title">Make yourself at home.</h2>
      <p>Understand your instance, install it and keep it running. Start at the beginning or go straight to the task in front of you.</p>
      <a class="text-action" href="/docs/operator-guide/">Open the operator manual <span aria-hidden="true">→</span></a>
    </div>
    <div class="manual-chapters">
      <a href="/docs/operator-guide/getting-started/"><span class="eyebrow">Get started</span><h3>Install your instance</h3><span aria-hidden="true">→</span></a>
      <a href="/docs/operator-guide/library-management/"><span class="eyebrow">Your collection</span><h3>Add and scan libraries</h3><span aria-hidden="true">→</span></a>
      <a href="/docs/operator-guide/monitoring/"><span class="eyebrow">Day to day</span><h3>Observe your instance</h3><span aria-hidden="true">→</span></a>
      <a href="/docs/operator-guide/backup-and-recovery/"><span class="eyebrow">Maintenance</span><h3>Back up and recover</h3><span aria-hidden="true">→</span></a>
    </div>
    <p class="developer-path">Working on Bånder itself? <a href="/docs/developer-guide/">Read the developer guide <span aria-hidden="true">→</span></a></p>
  </section>
</main>
<?php include __DIR__ . '/footer.php'; ?>
