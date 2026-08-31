<?php

declare(strict_types=1);

use App\Shared\Security\PasswordHasher;

require dirname(__DIR__) . '/bootstrap/autoload.php';

$username = strtolower(trim((string) ($argv[1] ?? 'records-admin')));
$role = strtolower(trim((string) ($argv[2] ?? 'admin')));
$firstName = trim((string) ($argv[3] ?? 'Records'));
$lastName = trim((string) ($argv[4] ?? 'Administrator'));

$validEmail = filter_var($username, FILTER_VALIDATE_EMAIL) !== false && strlen($username) <= 100;
$validUsername = preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $username) === 1;
if (!$validEmail && !$validUsername) {
    fwrite(STDERR, "Login ID must be a valid email address or username.\n");
    exit(1);
}

if (!in_array($role, ['viewer', 'operator', 'admin'], true)) {
    fwrite(STDERR, "Role must be viewer, operator, or admin.\n");
    exit(1);
}

foreach (['First name' => $firstName, 'Last name' => $lastName] as $label => $name) {
    if (preg_match("/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\x{2019}-]{0,48}$/u", $name) !== 1) {
        fwrite(STDERR, $label . " is required and must be no more than 49 characters.\n");
        exit(1);
    }
}

$password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
try {
    $passwordHash = PasswordHasher::hash($password);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

echo "Store the generated password in your password manager. It is shown only once.\n\n";
echo "RBAC entry: " . $username . '|' . $role . '|' . $firstName . '|' . $lastName . '|' . $passwordHash . "\n";
echo "PICKUPSHEET_RBAC_USERS='" . $username . '|' . $role . '|' . $firstName . '|' . $lastName . '|' . $passwordHash . "'\n";
echo "Generated password: " . $password . "\n";
