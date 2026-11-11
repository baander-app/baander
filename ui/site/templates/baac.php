<?php declare(strict_types=1);
$aac = $data['aac'];
include __DIR__ . '/head.php';
include __DIR__ . '/header.php';
$source = static fn (string $path): string => $escape($aac['sourceBase'] . $path);
$number = static fn (float|int $value, int $precision = 3): string => number_format($value, $precision, '.', ',');
$familyNotes = [
    'lc' => 'Five encoders, five nominal settings: 64, 96, 128, 192 and 256 kbit/s.',
    'he' => 'Four encoders at nominal 32, 48 and 64 kbit/s. FFmpeg native AAC has no observation in this profile.',
    'hev2' => 'Three encoders at nominal 24 and 32 kbit/s. Apple and FFmpeg have no observations in this profile.',
    'xhe' => 'bAAC and FDK at nominal 24, 32, 48, 64 and 96 kbit/s; libxaac at 64 and 96 only. Other combinations are unavailable.',
];
$lossless = $aac['charts']['lossless'];
$losslessShown = ['baac-5', 'baac-8', 'flac-5', 'flac-8', 'flac-ffmpeg-5', 'flac-ffmpeg-8', 'alac-ffmpeg', 'alac-apple-ref'];
$losslessRows = array_values(array_filter($lossless['rows'], static fn (array $row): bool => in_array($row['codec'], $losslessShown, true)));
$losslessRun = $lossless['run'];
$speed = static fn (?float $value): string => $value === null ? 'Not measured' : $number($value, 0) . '×';
?>
<main id="main" class="baac-page">
  <section class="baac-intro" aria-labelledby="baac-title">
    <p class="eyebrow">The codec library</p>
    <h1 id="baac-title">bAAC</h1>
    <p class="baac-lead">AAC and lossless FLAC encoding and decoding for Bånder.</p>
    <p>bAAC is a C++17 codec library with a C API, a command-line tool, a Node/Electron binding and an FFmpeg codec that Bånder's server transcoder uses by default. Its lossless mode writes and reads standard FLAC through the C API, the command-line tool and the binding. Explore what it implements and how its recorded measurements compare with other AAC, FLAC and ALAC encoders.</p>
    <div class="actions">
      <a class="button" href="<?= $source('docs/README.md') ?>">Read the source guide <span class="arrow" aria-hidden="true">↗</span></a>
      <a class="text-action" href="#quality">Compare the measurements <span class="arrow" aria-hidden="true">↓</span></a>
    </div>
    <p class="release-note">Pre-release · Version <?= $escape($aac['evidence']['version']) ?> · Source-build and API guidance</p>
  </section>

  <nav class="baac-navigation" aria-label="On this page">
    <a href="#integration">Integration</a>
    <a href="#quality">Quality</a>
    <a href="#throughput">Throughput</a>
    <a href="#lossless">Lossless</a>
    <a href="#methodology">Method and sources</a>
  </nav>

  <section class="baac-section" id="integration" aria-labelledby="integration-title">
    <div class="baac-section-heading">
      <p class="eyebrow">What it does</p>
      <h2 id="integration-title">A library, an FFmpeg codec and an Electron bridge.</h2>
    </div>
    <div class="baac-copy-grid">
      <div>
        <h3>Profiles and transports</h3>
        <p>The package exposes AAC-LC, HE-AAC v1/v2, AAC-LD/ELD and USAC/xHE-AAC. It handles ADTS, LOAS/LATM and configured raw access units, subject to profile restrictions. Raw MP4 sample support does not include an MP4 file demuxer.</p>
        <p>Implemented tools and checked interoperability differ. ER RVLC/HCR, LD-SBR and several USAC/spatial tools have approximations, unverified external streams or refused modes. Read the <a href="<?= $source('docs/README.md#what-it-does') ?>">capabilities and limitations</a> before choosing a profile.</p>
      </div>
      <div>
        <h3>Electron integration</h3>
        <p>Electron's main-process <code>AacService</code> exposes the native binding through registered IPC handlers and a preload bridge. The packaging configuration includes native files. This integration does not establish bAAC as the default playback path in the web application.</p>
        <p>Start with the <a href="<?= $source('docs/README.md#building') ?>">source-build guide</a>, <a href="<?= $source('docs/README.md#api') ?>">C API</a> and <a href="<?= $source('docs/README.md#node-api-binding-baanderaac-native') ?>">Node-API binding</a>. Public package availability and release binaries are not established by these measurements.</p>
      </div>
    </div>
    <div class="baac-note">
      <h3>Server transcoding through FFmpeg</h3>
      <p>Bånder's own FFmpeg build carries bAAC as the <code>libbaac</code> encoder and decoder, beside Fraunhofer's fdk-aac and FFmpeg's built-in AAC codec. In FFmpeg, bAAC encodes AAC-LC in mono, stereo and 5.1. The server transcoder uses it by default to encode AAC audio renditions and the audio of DASH video sessions, and to decode AAC sources for those encodes. Stream copies and probes keep FFmpeg's own handling.</p>
      <p>An operator can move the server to fdk-aac or the built-in codec with the <code>transcode.aac_implementation</code> setting, from the admin settings or the console. A DASH session keeps the implementation it started with, and a switch re-encodes no cached rendition. The operator guide's <a href="/docs/operator-guide/media/transcoding/#aac-implementation">AAC implementation</a> section describes the setting.</p>
    </div>
  </section>

  <section class="baac-section" id="quality" aria-labelledby="quality-title">
    <div class="baac-section-heading">
      <p class="eyebrow">Recorded quality</p>
      <h2 id="quality-title">Compare the rate and the model score.</h2>
      <p>These plots compare default constant-bitrate settings on ten TCDAudio14 music excerpts: 48 kHz, 16-bit stereo. Each point is a mean over the ten excerpts. The horizontal axis uses mean measured file rate, including transport framing; the tables also show the nominal setting.</p>
    </div>
    <aside class="baac-note" aria-label="Quality measurement limits">
      <p><strong>These are objective models, with no listening test.</strong> Higher is better for both PEAQ basic ODG and ViSQOL v3 MOS-LQO. Neither establishes a universal audible winner. PEAQ is not validated for SBR or parametric stereo; ViSQOL rewards bandwidth and this corpus is its own conformance material.</p>
      <p>bAAC's low-rate xHE-AAC results trail the references. The profile panels keep that weakness visible alongside AAC-LC, HE-AAC and HE-AAC v2. Speech, 44.1 kHz, multichannel and low-delay operation were not compared.</p>
    </aside>
    <p class="baac-dateline">Quality recorded 30 October 2026; bAAC remeasured 31 October. Reference scores remain from 30 October. <a href="#measurement-record">Measurement revisions and protocol</a>.</p>
    <?php foreach ($aac['charts']['quality'] as $family => $group): ?>
      <details class="baac-family"<?= $family === 'lc' ? ' open' : '' ?>>
        <summary><?= $escape($group['title']) ?><span>Quality · two models</span></summary>
        <p class="baac-family-note"><?= $escape($familyNotes[$family]) ?></p>
        <?php if ($family === 'xhe'): ?>
          <p class="baac-family-note"><strong>Low-rate limitation:</strong> bAAC trails FDK on the recorded low-rate ViSQOL scores. The current source documents further tool and interoperability limits; these recorded results do not measure subsequent code changes.</p>
        <?php endif; ?>
        <div class="baac-chart-grid">
          <?php foreach ($group['charts'] as $chart): ?>
            <figure class="baac-chart-panel" id="<?= $escape($chart['id']) ?>">
              <h3><?= $escape($chart['title']) ?></h3>
              <div class="baac-plot-scroll" role="region" tabindex="0" aria-label="<?= $escape($chart['title']) ?> chart; scroll horizontally if needed"><?= $chart['svg'] ?></div>
              <figcaption>Higher model score is better. Actual rate: kbit/s, mean of ten file rates. Lines connect observations as a visual guide, without equal-rate interpolation. Recorded 30–31 October 2026; TCDAudio14 music.</figcaption>
              <details class="baac-data">
                <summary>Inspect <?= count($chart['rows']) ?> data points</summary>
                <div class="baac-table-scroll" role="region" tabindex="0" aria-label="<?= $escape($chart['title']) ?> data">
                  <table>
                    <caption><?= $escape($chart['title']) ?> · ten excerpts per configuration</caption>
                    <thead><tr><th scope="col">Encoder</th><th scope="col">Nominal kbit/s</th><th scope="col">Actual kbit/s</th><th scope="col"><?= $chart['metric'] === 'peaq_basic' ? 'PEAQ basic ODG' : 'ViSQOL v3 MOS-LQO' ?></th></tr></thead>
                    <tbody>
                      <?php foreach ($chart['rows'] as $row): ?>
                        <tr data-config="<?= $escape($row['config']) ?>"><th scope="row"><?= $escape($row['label']) ?></th><td><?= $number($row['nominal'], 0) ?></td><td><?= $number($row['kbps']) ?></td><td data-value="<?= $escape((string) $row[$chart['metric']]) ?>"><?= $number($row[$chart['metric']]) ?></td></tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </details>
            </figure>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endforeach; ?>
    <p class="baac-source-note">55 configurations selected from the <a href="<?= $source('bench/results/quality_summary.csv') ?>">quality summary CSV</a>, checked against <a href="<?= $source('bench/results/quality.csv') ?>">per-excerpt rows</a>. VBR and experimental settings are outside these plots. Worst-item scores are not uncertainty bounds; no absolute-score confidence intervals are plotted.</p>
  </section>

  <section class="baac-section" id="throughput" aria-labelledby="throughput-title">
    <div class="baac-section-heading">
      <p class="eyebrow">Recorded throughput</p>
      <h2 id="throughput-title">Audio seconds per CPU second.</h2>
      <p>Higher is faster. Each bar divides input audio duration by CPU time (user plus system). Compare codecs within a profile and operation: encoding and decoding are separate workloads. Every bar starts at zero.</p>
    </div>
    <aside class="baac-note" aria-label="Throughput measurement limits">
      <p>Recorded 31 October 2026 on a four-core Intel Xeon 2.1 GHz cloud VM, Ubuntu 24.04, with single-threaded runs. This second run was reported to be about 20% slower than the first; timings from those runs are not combined. Apple measurements include Wine and do not represent current macOS performance.</p>
      <p>These values come from the dedicated speed dataset. Quality-stage timing under parallel load is excluded. Whole-process memory includes the input buffer and is not a codec working-memory measurement.</p>
    </aside>
    <?php foreach ($aac['charts']['speed'] as $family => $group): ?>
      <details class="baac-family"<?= $family === 'lc' ? ' open' : '' ?>>
        <summary><?= $escape($group['title']) ?><span>Encode and decode</span></summary>
        <p class="baac-family-note">TCDAudio14 music, 48 kHz, 16-bit stereo; 130.3173125 seconds of audio. Missing codec/profile combinations are unavailable, not zero. Decode input is a common FDK stream, or libxaac where FDK is unavailable.</p>
        <div class="baac-chart-grid">
          <?php foreach ($group['charts'] as $chart): ?>
            <figure class="baac-chart-panel" id="<?= $escape($chart['id']) ?>">
              <h3><?= $escape($chart['title']) ?></h3>
              <div class="baac-plot-scroll" role="region" tabindex="0" aria-label="<?= $escape($chart['title']) ?> chart; scroll horizontally if needed"><?= $chart['svg'] ?></div>
              <figcaption>Audio seconds / CPU second; higher is faster. Nominal <?= $number($chart['rows'][0]['nominal'], 0) ?> kbit/s workload. Recorded 31 October 2026.</figcaption>
              <details class="baac-data">
                <summary>Inspect <?= count($chart['rows']) ?> observations</summary>
                <div class="baac-table-scroll" role="region" tabindex="0" aria-label="<?= $escape($chart['title']) ?> data">
                  <table>
                    <caption><?= $escape($chart['title']) ?> · dedicated speed run</caption>
                    <thead><tr><th scope="col">Codec</th><th scope="col">Audio seconds</th><th scope="col">CPU seconds</th><th scope="col">Audio seconds / CPU second</th></tr></thead>
                    <tbody>
                      <?php foreach ($chart['rows'] as $row): ?>
                        <tr data-codec="<?= $escape($row['codec']) ?>"><th scope="row"><?= $escape($row['label']) ?></th><td><?= $number($row['seconds'], 7) ?></td><td><?= $number($row['cpu']) ?></td><td data-value="<?= $escape((string) $row['throughput']) ?>"><?= $number($row['throughput']) ?></td></tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </details>
            </figure>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endforeach; ?>
    <p class="baac-source-note">33 observations from <a href="<?= $source('bench/results/speed.json') ?>">speed.json</a>. Four encoder experiments and the zero-audio-duration Wine baseline are excluded; no baseline time is subtracted.</p>
  </section>

  <section class="baac-section" id="lossless" aria-labelledby="lossless-title">
    <div class="baac-section-heading">
      <p class="eyebrow">Lossless mode</p>
      <h2 id="lossless-title">Standard FLAC, measured level for level.</h2>
      <p>The lossless mode encodes PCM as standard FLAC (RFC 9639), and decoding the stream returns every sample bit for bit. Its encoder and decoder are bAAC's own, written from the RFC. Levels run from 0 to 8, and level 5 is the default; each level stays within the limits of the same-numbered preset of <code>flac</code>, the reference encoder. Every level is measured against <code>flac</code> at the same level. At levels 5 and 8 that comparison is a gate: bAAC's frame bytes may not exceed <code>flac</code>'s, and its speed may trail <code>flac</code>'s by no more than <code>flac</code>'s own run-to-run spread.</p>
    </div>
    <div class="baac-copy-grid">
      <div>
        <h3>Streams and files</h3>
        <p>The encoder writes and the decoder reads tags and pictures. <code>flac</code> and FFmpeg decode the encoder's streams to the input samples. The decoder passes over an ID3v2 tag in front of a stream and an ID3v1 tag behind its last frame, verifies every sample between them and reports both tags; the reference decoder reports the second as lost sync. Encoded bytes do not depend on the compiler, the optimization level, the processor or the C library: every CI runner has to reproduce fifteen streams pinned by their digests.</p>
      </div>
      <div>
        <h3>Held-out recordings</h3>
        <p>The gates also run on held-out recordings, which the encoder is never tuned on. The first held-out set, seven recordings from the FLAC decoder testbench, ran once before the encoder was final, and two of its encode gates failed. Four speed changes to the encoder followed. They were measured on the corpus and left every encoded byte as it was, but the failures had prompted them, so the seven recordings moved into the corpus, where they keep gates of their own. A fresh set judges the held-out gates instead: Variatio 4 of the Open Goldberg Variations (96 kHz, 24-bit), twenty seconds of the 5.1 master of the film Sintel and the audio of its trailer, fetched against pinned checksums.<?= $losslessRun !== null && $losslessRun['heldOutFirstRun'] ? ' The recorded run was the first to read them.' : '' ?></p>
      </div>
    </div>
    <?php if ($losslessRun !== null): ?>
      <p class="baac-dateline">Recorded <?= $escape($losslessRun['date']) ?> on <?= $escape($losslessRun['processor']) ?>.<?= $losslessRun['compiler'] === '' ? '' : ' bAAC was built with ' . $escape($losslessRun['compiler']) . '.' ?> The references were <?= $escape($losslessRun['flac']) ?> and <?= $escape($losslessRun['ffmpeg']) ?>. Each timed run is one process on one pinned core over the corpus repeated <?= $number($losslessRun['repetitions'], 0) ?> times. Compare speeds within this run only.</p>
    <?php endif; ?>
    <div class="baac-table-scroll" role="region" tabindex="0" aria-label="Lossless measurements">
      <table>
        <caption>Recorded lossless measurements · TCDAudio14 music, 48 kHz, 16-bit stereo · speed in audio seconds per CPU second</caption>
        <thead><tr><th scope="col">Encoder</th><th scope="col">FLAC frame bytes</th><th scope="col">kbit/s</th><th scope="col">Encode speed</th><th scope="col">Decode speed</th><th scope="col">Bit-exact check</th></tr></thead>
        <tbody>
          <?php foreach ($losslessRows as $row): ?>
            <tr data-codec="<?= $escape($row['codec']) ?>"><th scope="row"><?= $escape($row['label']) ?><?= $row['carried'] === null ? '' : ', carried from ' . $escape($row['carried']) ?></th><td><?= $row['frame_bytes'] === null ? 'Not recorded' : $number($row['frame_bytes'], 0) ?></td><td><?= $number($row['kbps'], 2) ?></td><td><?= $speed($row['encode']) ?></td><td><?= $speed($row['decode']) ?></td><td><?= $row['bit_exact'] ? 'Passed' : 'Failed' ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="baac-source-note">The table shows <?= count($losslessRows) ?> of the <?= count($lossless['rows']) ?> rows in <a href="<?= $source('bench/results/lossless.json') ?>">lossless.json</a>. Frame bytes count a file without its metadata blocks; the size gates compare frame bytes because <code>flac</code> writes padding and seek points by default. Apple's encoder, refalac, runs under Wine and is compared on size only. A carried row comes from the earlier run it names. The <a href="<?= $source('docs/codec-comparison.md#lossless') ?>">codec comparison</a> gives the size at every level and each gate's figures and verdict.</p>
  </section>

  <section class="baac-section baac-method" id="methodology" aria-labelledby="methodology-title">
    <div class="baac-section-heading">
      <p class="eyebrow">Method and provenance</p>
      <h2 id="methodology-title">Read the conditions behind the curves.</h2>
      <p>The site renders recorded repository data. Source links identify the latest commit affecting the bAAC package in this checkout; the measurement revisions below identify the code that produced the results.</p>
    </div>
    <details id="measurement-record" class="baac-method-detail" open>
      <summary>Measurement dates, revisions and timing</summary>
      <p>Quality was first measured on 30 October 2026 at <code>166dec8e5e32598cb0b9911755bfd4a417b9822b</code>. bAAC was remeasured on 31 October at <code>129ce927775beee5548aa9418829fa0fe2639b1f</code>; other encoders retain the first run's streams and scores. The tracked result revision is <code>9d05f8b1dcd6b04f61f046a9a42a453ef593a813</code>.</p>
      <p>The speed table is the second run of 31 October. bAAC used a Release build with GCC 13.3. The harness defaults to three timing repeats, selecting minimum CPU and wall time separately and maximum RSS. The actual repeat count is not stored in the raw results. Throughput here uses only the recorded CPU time. The raw files do not carry complete provenance metadata; these conditions come from the comparison document.</p>
    </details>
    <details class="baac-method-detail">
      <summary>Reference versions</summary>
      <ul>
        <li>FFmpeg 6.1.1: native AAC two-loop encoder and decoder.</li>
        <li>FDK: AOSP C++ tree <code>c41c3c7516ea8d0d78a53b96b30bed3a29745cc6</code>, rather than FFmpeg's linked <code>libfdk-aac</code> 2.0.3.</li>
        <li>Ittiam libxaac v0.1.14, revision <code>2fbadd57bca46693b7a077bcf1513131106d960c</code>.</li>
        <li>Apple CoreAudioToolbox 7.10.9.0 from iTunes for Windows 12.13.9.1, through qaac 2.85 and Wine 9.0, quality setting 2.</li>
      </ul>
      <p>These are measured references, not endorsements or runtime dependencies of bAAC.</p>
    </details>
    <details class="baac-method-detail">
      <summary>Corpus, meters and alignment</summary>
      <p>The corpus contains ten TCDAudio14 music excerpts, each about seven to sixteen seconds, totalling 130.3173125 seconds. Setup pins the ViSQOL corpus repository to <code>38d0b0163e441047d4429bf07ad09e5b9031d02c</code> and verifies file SHA-256 sums. That corpus pin does not identify the ViSQOL executable.</p>
      <p>GstPEAQ is pinned to <code>7397e49f5ede2f2429fb78f6663784e548401fec</code>. ViSQOL v3 uses audio mode and the default libsvm model; its exact executable revision was not recorded. PEAQ compresses severe impairments below about −3, and GstPEAQ reports conformance limitations. Scores are shown as observed, without clamping to a nominal scale.</p>
      <p>The pipeline uses FFmpeg to decode LC/HE streams and libxaac for xHE, with normalization and limiter off. It aligns decoded audio by cross-correlation, then matches levels below 3 kHz. Low-rate corrections reach about 3.2 dB on individual excerpts. Actual rates are arithmetic means of file rates, not duration-weighted corpus rates.</p>
      <p>The linked report's intervals are Student-t 95% half-widths of paired reference-minus-bAAC differences over ten items, using multiplier 2.262. They are not bootstrap intervals or confidence intervals for individual mean scores. Its equal-bitrate tables interpolate item curves; this page does not recompute or extrapolate them.</p>
    </details>
    <details class="baac-method-detail">
      <summary>Interoperability and code provenance</summary>
      <p>The recorded compatibility dataset has 790 attempts: 780 succeed and ten fail. The failures are bAAC decoding libxaac raw xHE64 inputs that require an unsupported config/size-list input path. All 440 attempts involving bAAC-encoded streams succeed in this dataset. This coverage does not establish universal decoder compatibility.</p>
      <p>Baander-authored code is Apache-2.0. The package's <a href="<?= $source('docs/README.md#provenance') ?>">provenance disclosure</a> identifies standards tables, consulted implementations and attributed data. Read it together with the capability limitations.</p>
    </details>
    <div class="baac-source-links">
      <h3>Inspect the recorded evidence</h3>
      <ul>
        <li><a href="<?= $source('docs/codec-comparison.md') ?>">Comparison method and interpretation</a></li>
        <li><a href="<?= $source('bench/results/tables.md') ?>">Detailed quality, paired-difference and lossless gate tables</a></li>
        <li><a href="<?= $source('bench/results/compat.json') ?>">Compatibility attempts</a></li>
        <li><a href="<?= $source('bench/README.md') ?>">Benchmark protocol</a>, <a href="<?= $source('bench/bench.py') ?>">harness</a>, <a href="<?= $source('bench/corpus.sh') ?>">corpus setup</a> and <a href="<?= $source('bench/setup-refs.sh') ?>">reference setup</a></li>
      </ul>
    </div>
  </section>
</main>
<?php include __DIR__ . '/footer.php'; ?>
