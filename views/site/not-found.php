<?php declare(strict_types=1); ?>
<section class="not-found">
    <div class="container" data-reveal>
        <p class="eyebrow">404 · Route not found</p>
        <h1>This path ends here.<br><em>The work doesn’t have to.</em></h1>
        <p>The page may have moved, or the address may be incomplete.</p>
        <a class="button button-primary" href="<?= htmlspecialchars($basePath . '/', ENT_QUOTES, 'UTF-8') ?>">Return home <span aria-hidden="true">→</span></a>
    </div>
</section>

