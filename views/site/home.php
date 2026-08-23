<?php declare(strict_types=1); ?>
<section class="hero-home">
    <div class="container hero-grid">
        <div class="hero-copy" data-reveal>
            <p class="eyebrow">Technology consulting · Product delivery</p>
            <h1>Turn complex work into <em>clear progress.</em></h1>
            <p class="hero-lede">T&amp;Tech designs digital products, connected operations, and technology systems that make organisations faster, clearer, and easier to run.</p>
            <div class="hero-actions">
                <a class="button button-primary" href="<?= htmlspecialchars($basePath . '/contact', ENT_QUOTES, 'UTF-8') ?>">Start a conversation <span aria-hidden="true">↗</span></a>
                <a class="text-link" href="<?= htmlspecialchars($basePath . '/services', ENT_QUOTES, 'UTF-8') ?>">Explore our services <span aria-hidden="true">→</span></a>
            </div>
        </div>
        <div class="hero-visual" aria-label="T and Tech delivery model" data-reveal>
            <div class="signal-orbit orbit-one"></div>
            <div class="signal-orbit orbit-two"></div>
            <div class="signal-core"><span>T</span><i>&amp;</i><span>T</span></div>
            <div class="signal-label label-strategy"><strong>01</strong> Strategy</div>
            <div class="signal-label label-systems"><strong>02</strong> Systems</div>
            <div class="signal-label label-delivery"><strong>03</strong> Delivery</div>
        </div>
    </div>
    <div class="container capability-strip" aria-label="Core capabilities">
        <span>Product engineering</span><i></i>
        <span>Workflow automation</span><i></i>
        <span>Data &amp; cloud</span><i></i>
        <span>Technical advisory</span>
    </div>
</section>

<section class="section section-services">
    <div class="container">
        <div class="section-heading split-heading" data-reveal>
            <div><p class="eyebrow">What we do</p><h2>From first decision to working system.</h2></div>
            <p>We combine business context, product thinking, and delivery discipline. The result is technology your team can understand, operate, and improve.</p>
        </div>
        <div class="service-grid">
            <a class="service-card" href="<?= htmlspecialchars($basePath . '/services#digital-products', ENT_QUOTES, 'UTF-8') ?>" data-reveal>
                <span class="service-number">01</span><i aria-hidden="true">↗</i>
                <h3>Digital product engineering</h3>
                <p>Focused web platforms and business applications built around real users and reliable operations.</p>
            </a>
            <a class="service-card" href="<?= htmlspecialchars($basePath . '/services#workflow-automation', ENT_QUOTES, 'UTF-8') ?>" data-reveal>
                <span class="service-number">02</span><i aria-hidden="true">↗</i>
                <h3>Workflow automation</h3>
                <p>Connected processes that replace repetitive handoffs with clear, auditable flows.</p>
            </a>
            <a class="service-card" href="<?= htmlspecialchars($basePath . '/services#data-cloud', ENT_QUOTES, 'UTF-8') ?>" data-reveal>
                <span class="service-number">03</span><i aria-hidden="true">↗</i>
                <h3>Data &amp; cloud systems</h3>
                <p>Practical infrastructure and reporting foundations that make information useful.</p>
            </a>
            <a class="service-card" href="<?= htmlspecialchars($basePath . '/services#technical-advisory', ENT_QUOTES, 'UTF-8') ?>" data-reveal>
                <span class="service-number">04</span><i aria-hidden="true">↗</i>
                <h3>Technical advisory</h3>
                <p>Independent guidance for architecture, delivery planning, and technology decisions.</p>
            </a>
        </div>
    </div>
</section>

<section class="section product-feature">
    <div class="container product-grid">
        <div class="product-copy" data-reveal>
            <p class="eyebrow eyebrow-red">T&amp;Tech product</p>
            <div class="product-brand">
                <img src="<?= htmlspecialchars($assetBase . '/dhl-logo.svg', ENT_QUOTES, 'UTF-8') ?>" alt="DHL">
                <span aria-hidden="true">—</span><strong>pickupsheet</strong>
            </div>
            <h2>Collection operations, kept in motion.</h2>
            <p>Pickupsheet brings scheduling, handoffs, and collection status into one focused operating view for logistics teams.</p>
            <a class="button button-red" href="<?= htmlspecialchars($basePath . '/pickupsheet', ENT_QUOTES, 'UTF-8') ?>">Explore Pickupsheet <span aria-hidden="true">→</span></a>
        </div>
        <div class="product-preview" aria-label="Pickupsheet interface preview" data-reveal>
            <div class="preview-bar"><span></span><span></span><span></span><small>Live manifest</small></div>
            <div class="preview-metrics"><article><small>Total pickups</small><strong>28</strong></article><article><small>Ready now</small><strong>08</strong></article><article><small>In transit</small><strong>12</strong></article></div>
            <div class="preview-row"><strong>PU-260823-04A</strong><span>Ikeja → Victoria Island</span><i>Ready</i></div>
            <div class="preview-row"><strong>PU-260823-07C</strong><span>Abuja → Wuse II</span><i>In transit</i></div>
        </div>
    </div>
</section>

<section class="section section-process">
    <div class="container process-grid">
        <div class="process-intro" data-reveal><p class="eyebrow eyebrow-light">How we work</p><h2>Less theatre.<br>More traction.</h2><p>Small, accountable teams. Clear decisions. Useful increments from the start.</p></div>
        <ol class="process-list">
            <li data-reveal><span>01</span><div><h3>Frame the opportunity</h3><p>Align the problem, users, constraints, and definition of value.</p></div></li>
            <li data-reveal><span>02</span><div><h3>Shape the system</h3><p>Turn priorities into a practical architecture and delivery path.</p></div></li>
            <li data-reveal><span>03</span><div><h3>Build and learn</h3><p>Deliver working increments, test assumptions, and adapt quickly.</p></div></li>
            <li data-reveal><span>04</span><div><h3>Transfer capability</h3><p>Document, train, and leave your team stronger than we found it.</p></div></li>
        </ol>
    </div>
</section>

