<?php declare(strict_types=1); ?>
<section class="hero-home">
    <div class="container hero-grid">
        <div class="hero-copy" data-reveal>
            <p class="eyebrow">Network outsourcing &middot; Managed solutions</p>
            <h1>Reliable networks. <em>Practical solutions.</em></h1>
            <p class="hero-lede">T&amp;Tech keeps critical infrastructure available, teams supported, and business operations moving through accountable network outsourcing and technology solutions.</p>
            <div class="hero-actions">
                <a class="button button-primary" href="<?= htmlspecialchars($basePath . '/contact', ENT_QUOTES, 'UTF-8') ?>">Talk to our team <span aria-hidden="true">&nearr;</span></a>
                <a class="text-link" href="<?= htmlspecialchars($basePath . '/services', ENT_QUOTES, 'UTF-8') ?>">Explore our services <span aria-hidden="true">&rarr;</span></a>
            </div>
        </div>
        <div class="hero-visual" aria-label="T and Tech operating model" data-reveal>
            <div class="signal-orbit orbit-one"></div>
            <div class="signal-orbit orbit-two"></div>
            <div class="signal-core"><span>T</span><i>&amp;</i><span>T</span></div>
            <div class="signal-label label-strategy"><strong>01</strong> Network</div>
            <div class="signal-label label-systems"><strong>02</strong> Support</div>
            <div class="signal-label label-delivery"><strong>03</strong> Solutions</div>
        </div>
    </div>
    <div class="container capability-strip" aria-label="Core capabilities">
        <span>Managed network operations</span><i></i>
        <span>Infrastructure support</span><i></i>
        <span>Cloud &amp; security</span><i></i>
        <span>Business solutions</span>
    </div>
</section>

<section class="technology-partners" aria-labelledby="technology-partners-heading">
    <div class="container partner-intro" data-reveal>
        <div>
            <p class="eyebrow">Strategic partners</p>
            <h2 id="technology-partners-heading">Connected to the platforms and operators enterprises trust.</h2>
        </div>
        <p>Our partner ecosystem strengthens the infrastructure, cloud, automation, enterprise solutions, and logistics operations we deliver and support.</p>
    </div>
    <div class="container partner-list" data-reveal>
        <a class="partner-logo partner-logo--aws" href="https://aws.amazon.com/" aria-label="Visit Amazon Web Services">
            <img src="<?= htmlspecialchars($assetBase . '/partners/aws.png', ENT_QUOTES, 'UTF-8') ?>" alt="Amazon Web Services (AWS) logo" width="1200" height="630" loading="lazy" decoding="async">
        </a>
        <a class="partner-logo partner-logo--microsoft" href="https://www.microsoft.com/" aria-label="Visit Microsoft">
            <img src="<?= htmlspecialchars($assetBase . '/partners/microsoft.png', ENT_QUOTES, 'UTF-8') ?>" alt="Microsoft logo" width="216" height="46" loading="lazy" decoding="async">
        </a>
        <a class="partner-logo partner-logo--ibm" href="https://www.ibm.com/" aria-label="Visit IBM">
            <img src="<?= htmlspecialchars($assetBase . '/partners/ibm.svg', ENT_QUOTES, 'UTF-8') ?>" alt="IBM logo" width="1075" height="401" loading="lazy" decoding="async">
        </a>
        <a class="partner-logo partner-logo--red-hat" href="https://www.redhat.com/" aria-label="Visit Red Hat">
            <img src="<?= htmlspecialchars($assetBase . '/partners/red-hat.svg', ENT_QUOTES, 'UTF-8') ?>" alt="Red Hat logo" width="360" height="180" loading="lazy" decoding="async">
        </a>
        <a class="partner-logo partner-logo--dhl" href="https://www.dhl.com/" aria-label="Visit DHL">
            <img src="<?= htmlspecialchars($assetBase . '/dhl-logo.svg', ENT_QUOTES, 'UTF-8') ?>" alt="DHL logo" width="287" height="40" loading="lazy" decoding="async">
        </a>
    </div>
    <p class="container partner-trademark-note">Amazon Web Services and AWS are trademarks of Amazon.com, Inc. or its affiliates. Microsoft is a trademark of the Microsoft group of companies. IBM and the IBM logo are trademarks of IBM Corp. Red Hat and the Red Hat logo are trademarks of Red Hat, Inc. DHL and the DHL logo are trademarks of DHL International GmbH or another DHL Group company.</p>
</section>

<section class="section section-services">
    <div class="container">
        <div class="section-heading split-heading" data-reveal>
            <div><p class="eyebrow">What we do</p><h2>One accountable partner for the systems your business depends on.</h2></div>
            <p>We combine day-to-day operational ownership with the expertise to improve, secure, and extend your technology environment.</p>
        </div>
        <div class="service-grid">
            <a class="service-card" href="<?= htmlspecialchars($basePath . '/services#network-outsourcing', ENT_QUOTES, 'UTF-8') ?>" data-reveal>
                <span class="service-number">01</span><i aria-hidden="true">&nearr;</i>
                <h3>Network outsourcing</h3>
                <p>Monitoring, incident response, vendor coordination, and performance management for reliable connectivity.</p>
            </a>
            <a class="service-card" href="<?= htmlspecialchars($basePath . '/services#managed-infrastructure', ENT_QUOTES, 'UTF-8') ?>" data-reveal>
                <span class="service-number">02</span><i aria-hidden="true">&nearr;</i>
                <h3>Managed infrastructure &amp; support</h3>
                <p>Responsive support and lifecycle management for the devices, systems, and people behind daily operations.</p>
            </a>
            <a class="service-card" href="<?= htmlspecialchars($basePath . '/services#cloud-security', ENT_QUOTES, 'UTF-8') ?>" data-reveal>
                <span class="service-number">03</span><i aria-hidden="true">&nearr;</i>
                <h3>Cloud, security &amp; continuity</h3>
                <p>Resilient cloud foundations, practical security controls, and recovery plans that reduce operational risk.</p>
            </a>
            <a class="service-card" href="<?= htmlspecialchars($basePath . '/services#business-solutions', ENT_QUOTES, 'UTF-8') ?>" data-reveal>
                <span class="service-number">04</span><i aria-hidden="true">&nearr;</i>
                <h3>Business systems &amp; integration</h3>
                <p>Operational platforms, automation, and integrations that connect technology to measurable business outcomes.</p>
            </a>
        </div>
    </div>
</section>

<section class="section section-process">
    <div class="container process-grid">
        <div class="process-intro" data-reveal><p class="eyebrow eyebrow-light">How we deliver</p><h2>Take ownership.<br>Build resilience.</h2><p>A practical managed-service approach with clear responsibility, visibility, and continuous improvement.</p></div>
        <ol class="process-list">
            <li data-reveal><span>01</span><div><h3>Assess the environment</h3><p>Understand the infrastructure, users, dependencies, risks, and service priorities.</p></div></li>
            <li data-reveal><span>02</span><div><h3>Take operational ownership</h3><p>Set responsibilities, service levels, escalation paths, and reporting expectations.</p></div></li>
            <li data-reveal><span>03</span><div><h3>Stabilise and secure</h3><p>Resolve immediate gaps, improve visibility, and protect the systems that matter most.</p></div></li>
            <li data-reveal><span>04</span><div><h3>Improve continuously</h3><p>Use operational insight to improve performance, resilience, capacity, and user experience.</p></div></li>
        </ol>
    </div>
</section>
