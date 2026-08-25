<?php

declare(strict_types=1);

$password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
if (!is_string($passwordHash)) {
    fwrite(STDERR, "Unable to generate a password hash.\n");
    exit(1);
}

echo "Store the generated password in your password manager. It is shown only once.\n\n";
echo "PICKUPSHEET_RECORDS_USER=records-admin\n";
echo "PICKUPSHEET_RECORDS_PASSWORD_HASH='" . $passwordHash . "'\n";
echo "Generated password: " . $password . "\n";
