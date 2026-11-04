<?php declare(strict_types=1);
$type = $data['typography'];
include __DIR__ . '/head.php';
include __DIR__ . '/header.php';
// The script markers the app's content text writes: any Thai, Burmese, Hangul or CJK character removes
// tracking, each script present adds its line-height marker, and wholly Burmese text gets the size boost.
$scripts = static function (string $text): string {
    $cjk = preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $text) === 1;
    $thai = preg_match('/\p{Thai}/u', $text) === 1;
    $burmese = preg_match('/\p{Myanmar}/u', $text) === 1;
    $markers = [
        'no-tracking' => $cjk || $thai || $burmese,
        'cjk' => $cjk,
        'thai' => $thai,
        'burmese' => $burmese,
        'burmese-only' => $burmese && preg_match('/^[\p{Myanmar}\p{N}\p{P}\p{Z}\s\x{200B}-\x{200D}]*$/u', $text) === 1,
    ];
    return implode(' ', array_keys(array_filter($markers)));
};
$sample = static function (string $role, string $lang, string $text, string $class = '') use ($escape, $scripts): string {
    $markers = $scripts($text);
    return '<p class="type type-' . $role . ($class === '' ? '' : ' ' . $class) . '" lang="' . $escape($lang) . '"'
        . ($markers === '' ? '' : ' data-script="' . $markers . '"') . '>' . $escape($text) . '</p>';
};
$tracking = static fn (string $value): string => match (true) {
    (float) $value === 0.0 => '0',
    str_starts_with($value, '-') => '−' . substr($value, 1),
    default => '+' . $value,
};
$rules = [
    'tracking' => [
        'title' => 'Zero tracking for Thai, Burmese, Hangul and CJK',
        'copy' => 'An element containing any Thai, Burmese, Hangul or CJK character has zero letter-spacing throughout, including in tracked roles such as label, hero and the titles. These samples use the label role, tracked +0.12em for Latin text.',
        'without' => 'typography-without-tracking',
    ],
    'line-height' => [
        'title' => 'Taller lines for CJK, Thai and Burmese',
        'copy' => 'An element takes the larger of its role’s line-height and the minimum of the scripts it contains: CJK and Korean 1.6, Thai 1.8, Burmese 2.0. These samples use the body role, whose line-height is 1.5, so stacked Thai and Burmese marks stay clear of the next line.',
        'without' => 'typography-without-line-height',
    ],
    'size' => [
        'title' => '2px larger for wholly Burmese text',
        'copy' => 'A string written wholly in Burmese, apart from digits, spaces and punctuation, renders 2px larger than its role. A mixed string, such as the second sample, keeps the role size.',
        'without' => 'typography-without-size',
    ],
    'numerals' => [
        'title' => 'Tabular numerals for timecodes',
        'copy' => 'Durations and timecodes use the timecode or mono-sm role. Both set tabular numerals in Geist Mono, so digits keep their width as they change. Without the rule, the same timecodes in proportional Geist digits do not line up.',
        'without' => 'typography-without-numerals',
    ],
];
?>
<main id="main" class="typography-page">
  <section class="typography-intro" aria-labelledby="typography-title">
    <p class="eyebrow">Type and scripts</p>
    <h1 id="typography-title">Typography</h1>
    <p class="typography-lead">Every title in its own script, set in bundled fonts.</p>
    <p>Bånder sets its interface in Geist and Geist Mono. Thai, Burmese, Korean, Chinese and Japanese text falls back, glyph by glyph, to Noto fonts that Bånder serves itself, and script rules adjust spacing and size from the characters each element contains.</p>
    <p>Every sample on this page is live text in those fonts. You can select, copy and zoom it. This page loads only the font slices its own text needs; the other pages on this site load Latin Geist only.</p>
  </section>

  <nav class="typography-navigation" aria-label="On this page">
    <a href="#scripts">Scripts</a>
    <a href="#glyph-forms">Glyph forms</a>
    <a href="#script-rules">Script rules</a>
    <a href="#type-scale">Type scale</a>
  </nav>

  <section class="typography-section" id="scripts" aria-labelledby="scripts-title">
    <div class="typography-section-heading">
      <p class="eyebrow">Supported scripts</p>
      <h2 id="scripts-title">Each script in a bundled font.</h2>
      <p>Geist and Geist Mono cover Latin, Latin Extended, Vietnamese and Cyrillic. Every other script falls through, in this default order, to Noto Sans, Noto Sans Thai, Noto Sans Myanmar, Noto Sans SC, Noto Sans TC, Noto Sans JP and Noto Sans KR. In a mono context, such as a file name, Noto Sans Mono comes first and the same chain follows, so the text never falls to a system font.</p>
    </div>
    <div class="typography-specimens">
      <?php foreach ($type['specimens'] as $index => $specimen): ?>
        <article class="typography-specimen" aria-labelledby="specimen-<?= $index ?>">
          <h3 id="specimen-<?= $index ?>"><?= $escape($specimen['script']) ?></h3>
          <p class="typography-family"><?= $escape($specimen['family']) ?></p>
          <div class="typography-samples">
            <?php foreach ($specimen['samples'] as [$role, $lang, $text]): ?>
              <?= $sample($role, $lang, $text) ?>
            <?php endforeach; ?>
          </div>
          <p class="typography-roles">Set in <?= $escape(implode(', ', array_column($specimen['samples'], 0))) ?>.</p>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="typography-section" id="glyph-forms" aria-labelledby="glyph-forms-title">
    <div class="typography-section-heading">
      <p class="eyebrow">Han glyph forms</p>
      <h2 id="glyph-forms-title">The same characters, three forms.</h2>
      <p>Japanese, Simplified Chinese and Traditional Chinese draw some shared Han characters differently, so the item’s language decides the font: Japanese puts Noto Sans JP first, Traditional Chinese puts Noto Sans TC first, and every other language keeps Noto Sans SC first.</p>
    </div>
    <div class="typography-forms">
      <?php foreach ($type['forms'] as $form): ?>
        <figure class="typography-form">
          <figcaption><?= $escape($form['language']) ?><span><?= $escape($form['family']) ?> · lang="<?= $escape($form['lang']) ?>"</span></figcaption>
          <p class="typography-glyphs" lang="<?= $escape($form['lang']) ?>" data-script="<?= $scripts($type['han']) ?>"><?= $escape(implode(' ', mb_str_split($type['han']))) ?></p>
        </figure>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="typography-section" id="script-rules" aria-labelledby="script-rules-title">
    <div class="typography-section-heading">
      <p class="eyebrow">Script rules</p>
      <h2 id="script-rules-title">The characters decide the spacing.</h2>
      <p>Script rules depend on the characters an element contains, and they override the type role where the two conflict. One decision covers the whole element, and the most demanding script present wins.</p>
    </div>
    <?php foreach ($rules as $id => $rule): ?>
      <?php $examples = $type['rules'][$id]; ?>
      <article class="typography-rule" id="rule-<?= $id ?>" aria-labelledby="rule-<?= $id ?>-title">
        <h3 id="rule-<?= $id ?>-title"><?= $escape($rule['title']) ?></h3>
        <p><?= $escape($rule['copy']) ?></p>
        <div class="typography-compare">
          <?php foreach (['without' => 'Without the rule', 'with' => 'With the rule'] as $variant => $label): ?>
            <div class="typography-panel" data-variant="<?= $variant ?>" role="group" aria-label="<?= $escape($rule['title']) ?>: <?= $escape(strtolower($label)) ?>">
              <p class="typography-panel-label"><?= $label ?></p>
              <div class="typography-panel-samples">
                <?php foreach ($examples['samples'] as [$lang, $text]): ?>
                  <?= $sample($examples['role'], $lang, $text, $variant === 'without' ? $rule['without'] : '') ?>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="typography-section" id="type-scale" aria-labelledby="type-scale-title">
    <div class="typography-section-heading">
      <p class="eyebrow">Type scale</p>
      <h2 id="type-scale-title">Ten roles for every text element.</h2>
      <p>Every text element uses one of the ten type roles. Phone sizes apply at a window width of 767px or less; the other values stay the same at every width. Weights run from 400 to 700, and every bundled family is variable, so each weight has a real face. The samples follow your window’s width.</p>
    </div>
    <ol class="typography-scale">
      <?php foreach ($type['roles'] as $role => $values): ?>
        <li class="typography-role" data-role="<?= $escape($role) ?>">
          <div class="typography-role-meta">
            <h3><code><?= $escape($role) ?></code></h3>
            <dl>
              <div><dt>Desktop</dt><dd><?= $escape($values['desktop']) ?></dd></div>
              <div><dt>Phone</dt><dd><?= $escape($values['phone']) ?></dd></div>
              <div><dt>Weight</dt><dd><?= $escape($values['weight'] . ($values['mono'] ? ', mono' : '')) ?></dd></div>
              <div><dt>Tracking</dt><dd><?= $escape($tracking($values['tracking']) . ($values['transform'] === null ? '' : ', ' . $values['transform'])) ?></dd></div>
              <div><dt>Line-height</dt><dd><?= $escape($values['lineHeight']) ?></dd></div>
            </dl>
            <p><?= $escape($values['use']) ?></p>
          </div>
          <?= $sample($role, 'en', $values['sample']) ?>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>
</main>
<?php include __DIR__ . '/footer.php'; ?>
