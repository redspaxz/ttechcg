<?php

declare(strict_types=1);

$username = trim((string) ($argv[1] ?? 'records-admin'));
$role = strtolower(trim((string) ($argv[2] ?? 'admin')));

if (preg_match('/^[A-Za-z0-9._@-]{1,100}$/', $username) !== 1) {
    fwrite(STDERR, "Username may contain only letters, numbers, dots, underscores, @, and hyphens.\n");
    exit(1);
}

if (!in_array($role, ['viewer', 'operator', 'admin'], true)) {
    fwrite(STDERR, "Role must be viewer, operator, or admin.\n");
    exit(1);
}

$password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
if (!is_string($passwordHash)) {
    fwrite(STDERR, "Unable to generate a password hash.\n");
    exit(1);
}

echo "Store the generated password in your password manager. It is shown only once.\n\n";
echo "RBAC entry: " . $username . '|' . $role . '|' . $passwordHash . "\n";
echo "PICKUPSHEET_RBAC_USERS='" . $username . '|' . $role . '|' . $passwordHash . "'\n";
echo "Generated password: " . $password . "\n";
