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
      <?php
      $screenshot = [
          'name' => 'music',
          'alt' => "Bånder's music library in the browser, with album artwork and playback controls.",
          'width' => 1600,
          'height' => 1000,
      ];
      include __DIR__ . '/screenshot.php';
      ?>
      <figcaption>Music browsing and playback · Captured in Bånder</figcaption>
    </figure>
  </section>

  <section class="home-section eq-carousel" aria-labelledby="equalizer-title" data-carousel>
    <div class="section-intro carousel-intro">
      <p class="eyebrow">The equalizer</p>
      <h2 id="equalizer-title">Find your sound.</h2>
      <p>Start with a preset or adjust the sound yourself. Compare settings and save profiles for the way you listen.</p>
    </div>
    <div class="carousel-slides">
      <div class="experience-feature carousel-slide" id="eq-graphic" data-carousel-slide data-slide-title="Graphic EQ and input analyzer">
        <div class="section-intro">
          <p class="eyebrow">Graphic EQ and presets</p>
          <h3>A starting point.<br>Then your own adjustments.</h3>
          <p>Choose a preset, then adjust ten frequency bands to shape bass, mids and treble.</p>
          <p>The live input analyzer shows the signal before EQ and gain. View its spectrum alongside the configured EQ curve, check left and right levels, or inspect the stereo phase.</p>
        </div>
        <figure class="app-screenshot feature-screenshot">
          <?php
          $screenshot = [
              'name' => 'equalizer',
              'alt' => "Bånder's graphic equalizer, presets and live input analyzer.",
              'width' => 1600,
              'height' => 1000,
              'loading' => 'lazy',
          ];
          include __DIR__ . '/screenshot.php';
          ?>
          <figcaption>Graphic EQ and input analyzer · Captured in Bånder</figcaption>
        </figure>
      </div>
      <div class="experience-feature carousel-slide" id="eq-parametric" data-carousel-slide data-slide-title="Parametric controls">
        <div class="section-intro">
          <p class="eyebrow">Parametric controls</p>
          <h3>See the shape of your EQ.</h3>
          <p>Switch to the parametric view to work with bands on a response graph. Drag a point to adjust its position and gain, and inspect the selected band's frequency, gain and Q value.</p>
          <p>The curve shows the bands together, so you can see their boosts and cuts in one view.</p>
        </div>
        <figure class="app-screenshot feature-screenshot">
          <?php
          $screenshot = [
              'name' => 'equalizer-parametric',
              'alt' => "Bånder's parametric equalizer with a response curve and selectable band points.",
              'width' => 1600,
              'height' => 1000,
              'loading' => 'lazy',
          ];
          include __DIR__ . '/screenshot.php';
          ?>
          <figcaption>Parametric EQ · Captured in Bånder</figcaption>
        </figure>
      </div>
      <div class="experience-feature carousel-slide" id="eq-processing" data-carousel-slide data-slide-title="Audio processing">
        <div class="section-intro">
          <p class="eyebrow">Audio processing</p>
          <h3>More than frequency bands.</h3>
          <p>Adjust the compressor's threshold, ratio, knee, attack and release. Change stereo width or choose a crossfeed preset for headphone listening.</p>
          <p>A loudness contour boosts bass and treble at low volume. Master gain and optional normalization with a loudness target give you separate controls over level.</p>
        </div>
        <figure class="app-screenshot feature-screenshot">
          <?php
          $screenshot = [
              'name' => 'equalizer-processing',
              'alt' => "Bånder's audio processing controls for compression, stereo width, crossfeed, loudness and master gain.",
              'width' => 652,
              'height' => 517,
              'loading' => 'lazy',
          ];
          include __DIR__ . '/screenshot.php';
          ?>
          <figcaption>Audio processing · Captured in Bånder</figcaption>
        </figure>
      </div>
      <div class="experience-feature carousel-slide" id="eq-profiles" data-carousel-slide data-slide-title="A/B comparison and device profiles">
        <div class="section-intro">
          <p class="eyebrow">Compare and keep</p>
          <h3>Hear the difference.<br>Keep what works.</h3>
          <p>Capture two sets of EQ and processing settings in A and B, then switch between them to compare.</p>
          <p>Save your current settings to named device profiles. Keep a profile for your headphones and another for your speakers, and activate the one you want to use.</p>
        </div>
        <figure class="app-screenshot feature-screenshot">
          <?php
          $screenshot = [
              'name' => 'equalizer-profiles',
              'alt' => "Bånder's A/B comparison slots and named device profiles.",
              'width' => 1600,
              'height' => 1000,
              'loading' => 'lazy',
          ];
          include __DIR__ . '/screenshot.php';
          ?>
          <figcaption>A/B comparison and device profiles · Captured in Bånder</figcaption>
        </figure>
      </div>
    </div>
    <div class="carousel-controls" data-carousel-controls hidden>
      <button type="button" class="carousel-arrow" data-carousel-previous aria-label="Previous equalizer feature"><span aria-hidden="true">←</span></button>
      <div class="carousel-dots" role="group" aria-label="Choose an equalizer feature">
        <button type="button" data-carousel-index="0" aria-label="Show graphic EQ and input analyzer" aria-controls="eq-graphic"><span aria-hidden="true"></span></button>
        <button type="button" data-carousel-index="1" aria-label="Show parametric controls" aria-controls="eq-parametric"><span aria-hidden="true"></span></button>
        <button type="button" data-carousel-index="2" aria-label="Show audio processing" aria-controls="eq-processing"><span aria-hidden="true"></span></button>
        <button type="button" data-carousel-index="3" aria-label="Show A/B comparison and device profiles" aria-controls="eq-profiles"><span aria-hidden="true"></span></button>
      </div>
      <button type="button" class="carousel-arrow" data-carousel-next aria-label="Next equalizer feature"><span aria-hidden="true">→</span></button>
      <p class="carousel-status" role="status" data-carousel-status></p>
    </div>
  </section>

  <section class="home-section experience-feature experience-feature-reversed" aria-labelledby="playlists-title">
    <div class="section-intro">
      <p class="eyebrow">Your playlists</p>
      <h2 id="playlists-title">Choose the songs.<br>Or set the rules.</h2>
      <p>Pick songs yourself, or create smart playlists with rules that select music from your catalog.</p>
      <p>Share playlists with other people on your server, and choose whether collaborators can change the track list.</p>
    </div>
    <figure class="app-screenshot feature-screenshot">
      <?php
      $screenshot = [
          'name' => 'playlists',
          'alt' => "Bånder's playlists view in the browser.",
          'width' => 1600,
          'height' => 1000,
          'loading' => 'lazy',
      ];
      include __DIR__ . '/screenshot.php';
      ?>
      <figcaption>Playlists · Captured in Bånder</figcaption>
    </figure>
  </section>

  <section class="home-section wide-feature" aria-labelledby="destinations-title">
    <div class="section-intro wide-feature-intro">
      <div>
        <p class="eyebrow">Playback destinations</p>
        <h2 id="destinations-title">Start in one room.<br>Keep listening in another.</h2>
      </div>
      <div>
        <p>Move what's playing to another device where you're signed in to Bånder, such as the living-room computer, a tablet or your phone. The track, the queue, the position and whether it was playing or paused all move with it.</p>
        <p>Pause, play and seek from the device you started on, or bring playback back. Movies move between devices the same way.</p>
        <aside class="feature-note" aria-label="Planned casting support">
          <p><strong>Planned: AirPlay, Chromecast and DLNA.</strong> Casting to these receivers is in development and isn't available yet.</p>
        </aside>
      </div>
    </div>
    <figure class="app-screenshot feature-screenshot">
      <?php
      $screenshot = [
          'name' => 'destinations',
          'alt' => "Bånder's playback destination menu, showing music playing on the living-room device with other signed-in devices listed.",
          'width' => 2272,
          'height' => 1420,
          'loading' => 'lazy',
      ];
      include __DIR__ . '/screenshot.php';
      ?>
      <figcaption>Playback destinations · Captured in Bånder with sample music</figcaption>
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
      <p>Bånder is pre-release software. The current installation builds from source using a development-oriented Compose stack. You need to prepare the network, storage and credentials before starting it.</p>
      <p>The web application uses PostgreSQL and Redis. Background work needs a separate control and execution deployment pair, shared media paths and output storage. Starting the web service alone does not start that pair.</p>
      <a class="button" href="/docs/operator-guide/getting-started/">Read the installation guide <span aria-hidden="true">→</span></a>
    </div>
    <div class="hosting-reference">
      <p class="eyebrow">Before your first scan</p>
      <a href="/docs/operator-guide/understanding-baander/"><span>The components</span><span aria-hidden="true">↗</span></a>
      <a href="/docs/operator-guide/storage-and-data/"><span>Storage and data</span><span aria-hidden="true">↗</span></a>
      <a href="/docs/operator-guide/background-work/"><span>Background work</span><span aria-hidden="true">↗</span></a>
      <p>Use the guide's checkpoints to verify your installation, catalog and playback.</p>
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
