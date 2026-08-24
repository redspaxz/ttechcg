<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$services = [
    'network-outsourcing' => 'Network outsourcing',
    'managed-infrastructure' => 'Managed infrastructure & support',
    'cloud-security' => 'Cloud, security & continuity',
    'business-solutions' => 'Business systems & integration',
    'btspos' => 'BTSPOS',
    'other' => 'Something else',
];
?>
<section class="contact-section">
    <div class="container contact-grid">
        <div class="contact-intro" data-reveal>
            <p class="eyebrow">Start a conversation</p>
            <h1>Bring us the operation that <em>must keep moving.</em></h1>
            <p>Share the network challenge, support requirement, or solution opportunity. We’ll respond with focused questions and a practical next step.</p>
            <div class="contact-notes">
                <article><span>01</span><div><strong>Tell us what depends on the technology</strong><p>Availability, users, locations, and business impact give us the right starting point.</p></div></article>
                <article><span>02</span><div><strong>We’ll define the next practical step</strong><p>Expect a clear conversation about scope, ownership, risk, and the way forward.</p></div></article>
            </div>
        </div>

        <div class="contact-form-wrap" data-reveal>
            <?php if (!$contactOperational): ?>
                <div class="notice notice-error" role="alert">Online inquiries are temporarily unavailable. Please try again later.</div>
            <?php endif; ?>
            <?php if (is_string($flash) && $flash !== ''): ?>
                <div class="notice notice-success" role="status"><?= $e($flash) ?></div>
            <?php endif; ?>
            <?php if ($errors !== []): ?>
                <div class="notice notice-error" role="alert">
                    <?php foreach ($errors as $error): ?><span><?= $e($error) ?></span><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form class="contact-form" method="post" action="<?= $e($basePath) ?>/contact">
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                <label class="honeypot" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>
                <div class="form-row">
                    <label><span>Your name</span><input name="name" maxlength="100" value="<?= $e($old['name'] ?? '') ?>" required autocomplete="name" placeholder="Full name"></label>
                    <label><span>Work email</span><input type="email" name="email" maxlength="160" value="<?= $e($old['email'] ?? '') ?>" required autocomplete="email" placeholder="name@company.com"></label>
                </div>
                <div class="form-row">
                    <label><span>Company <small>Optional</small></span><input name="company" maxlength="140" value="<?= $e($old['company'] ?? '') ?>" autocomplete="organization" placeholder="Organisation"></label>
                    <label><span>Area of interest</span><select name="service" required><option value="">Select one</option><?php foreach ($services as $value => $label): ?><option value="<?= $e($value) ?>" <?= ($old['service'] ?? '') === $value ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label>
                </div>
                <label><span>What would you like to move forward?</span><textarea name="message" minlength="20" maxlength="2000" rows="7" required placeholder="A little context on the challenge, desired outcome, and timing."><?= $e($old['message'] ?? '') ?></textarea></label>
                <button class="button button-primary button-submit" type="submit" <?= !$contactOperational ? 'disabled' : '' ?>>Send inquiry <span aria-hidden="true">↗</span></button>
                <p class="form-note">Your details are used only to respond to this inquiry. Read our <a href="<?= $e($basePath) ?>/privacy">privacy notice</a>.</p>
            </form>
        </div>
    </div>
</section>
