<?php declare(strict_types=1); ?>
<footer class="site-footer">
  <a class="footer-brand" href="/">Bånder</a>
  <p>A media server you host. <a href="https://www.juul.xyz/">Made by Martin Juul</a>.</p>
  <nav aria-label="Footer navigation">
    <a href="/docs/operator-guide/">Operator manual</a>
    <a href="/docs/developer-guide/">Developer guide</a>
    <a href="/bac/">Audio Core</a>
    <a href="/typography/">Typography</a>
    <a href="<?= $escape($site['repositoryUrl']) ?>">Source</a>
  </nav>
</footer>
</body>
</html>
