<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$trackedOutput = [];
$exitCode = 0;
exec('git -C ' . escapeshellarg($root) . ' ls-files --cached --others --exclude-standard', $trackedOutput, $exitCode);
if ($exitCode !== 0) {
    fwrite(STDERR, "Unable to enumerate tracked files.\n");
    exit(1);
}

$failures = [];
$forbiddenNames = ['.env', '.env.local', '.env.production'];
$secretPatterns = [
    'private key' => '/-----BEGIN (?:EC |OPENSSH |RSA )?PRIVATE KEY-----/',
    'AWS access key' => '/\bAKIA[0-9A-Z]{16}\b/',
    'GitHub token' => '/\bgh[opsu]_[A-Za-z0-9_]{30,}\b/',
    'live environment secret' => '/^[ \t]*(?:DB_PASSWORD|JUMPCLOUD_OIDC_CLIENT_SECRET|PICKUPSHEET_MFA_ENCRYPTION_KEY)[ \t]*=[ \t]*(?=\S)(?!(?:replace|paste|change|example)[-_ ]?)/mi',
];

foreach ($trackedOutput as $relativePath) {
    $normalized = str_replace('\\', '/', trim($relativePath));
    if ($normalized === '') {
        continue;
    }
    if (in_array(strtolower(basename($normalized)), $forbiddenNames, true)) {
        $failures[] = $normalized . ': environment files must never be tracked';
        continue;
    }
    if (preg_match('/\.(?:key|p12|pfx|pem)$/i', $normalized) === 1) {
        $failures[] = $normalized . ': private credential files must never be tracked';
        continue;
    }

    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    if (!is_file($path) || filesize($path) === false || filesize($path) > 2_000_000) {
        continue;
    }
    $contents = file_get_contents($path);
    if (!is_string($contents) || str_contains($contents, "\0")) {
        continue;
    }
    foreach ($secretPatterns as $label => $pattern) {
        if (preg_match($pattern, $contents) === 1) {
            $failures[] = $normalized . ': possible ' . $label;
        }
    }
}

$workflowPaths = array_filter($trackedOutput, static fn (string $path): bool => str_starts_with(str_replace('\\', '/', $path), '.github/workflows/'));
foreach ($workflowPaths as $workflowPath) {
    $contents = file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $workflowPath));
    if (is_string($contents) && preg_match('/^\s*uses:\s*[^\r\n@]+@(?![a-f0-9]{40}(?:\s|#|$))/mi', $contents) === 1) {
        $failures[] = $workflowPath . ': GitHub Actions must be pinned to a full commit SHA';
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Security check failed:\n - " . implode("\n - ", array_unique($failures)) . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf("Security check passed for %d repository files.\n", count($trackedOutput)));
