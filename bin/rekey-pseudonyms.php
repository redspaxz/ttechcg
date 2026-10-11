<?php

declare(strict_types=1);

/*
 * Converts stored actor identifiers from unkeyed SHA-256 to the keyed form used once
 * SECURITY_PSEUDONYM_KEY is set. Run once, after setting the key and taking a backup:
 *
 *   php bin/rekey-pseudonyms.php           # report what would change
 *   php bin/rekey-pseudonyms.php --apply   # rewrite in one transaction
 *
 * bin/ is not deployed to the web root, so on cPanel run it from the repository clone and
 * point it at the deployed configuration:
 *
 *   php bin/rekey-pseudonyms.php --env=/home/ttecwymc/public_html/.env
 *
 * Usernames come from the environment administrators, local accounts, MFA enrolments,
 * changed administrator passwords, and session activity. An identifier whose username
 * is no longer known anywhere stays in the legacy form and shows as an unknown actor.
 * Client identifiers are not rewritten: they cannot be reversed by design.
 */

use App\Shared\Infrastructure\Database;
use App\Shared\Infrastructure\Environment;
use App\Shared\Security\Pseudonym;
use App\Shared\Security\RecordsAccess;

$root = dirname(__DIR__);
require $root . '/bootstrap/autoload.php';
$envPath = $root . '/.env';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--env=')) {
        $envPath = substr($argument, strlen('--env='));
        if (!is_file($envPath) || !is_readable($envPath)) {
            fwrite(STDERR, "Cannot read the environment file {$envPath}.\n");
            exit(1);
        }
    }
}
Environment::load($envPath);

$apply = in_array('--apply', array_slice($argv, 1), true);
Pseudonym::configure((string) (getenv('SECURITY_PSEUDONYM_KEY') ?: ''));
if (!Pseudonym::isKeyed()) {
    fwrite(STDERR, "SECURITY_PSEUDONYM_KEY must be set (at least 32 characters) before rekeying.\n");
    exit(1);
}

/** Every column that stores Pseudonym::actorId() values. */
const ACTOR_COLUMNS = [
    'pickup_sheets' => ['paid_by', 'deleted_by'],
    'pickup_sheet_edit_audit' => ['actor_id'],
    'pickup_sheet_lifecycle_audit' => ['actor_id'],
    'pickup_sheet_settings' => ['updated_by'],
    'pickup_records_users' => ['created_by', 'updated_by'],
    'pickup_records_admin_credentials' => ['updated_by'],
    'pickup_local_mfa' => ['updated_by'],
    'pickup_auth_settings' => ['updated_by'],
    'pickup_customers' => ['created_by', 'updated_by', 'assigned_actor_id'],
    'pickup_customer_reward_adjustments' => ['actor_id'],
    'pickup_customer_activities' => ['actor_id'],
    'pickup_customer_aliases' => ['created_by'],
    'pickup_customer_merges' => ['merged_by', 'undone_by', 'dismissed_by'],
    'pickup_customer_duplicate_dismissals' => ['dismissed_by'],
    'pickup_security_events' => ['actor_id', 'target_id'],
];
const USERNAME_SOURCES = [
    'pickup_records_users',
    'pickup_records_admin_credentials',
    'pickup_local_mfa',
    'pickup_records_session_activity',
];

$connection = Database::connect(true);
$existingColumns = [];
foreach ($connection->query(
    'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()',
) as $row) {
    $existingColumns[$row['TABLE_NAME']][$row['COLUMN_NAME']] = true;
}

$usernames = RecordsAccess::fromEnvironment()->environmentUsernames();
foreach (USERNAME_SOURCES as $table) {
    if (isset($existingColumns[$table]['username'])) {
        $usernames = array_merge(
            $usernames,
            $connection->query('SELECT DISTINCT username FROM ' . $table)->fetchAll(PDO::FETCH_COLUMN),
        );
    }
}
$mapping = [];
foreach (array_unique(array_filter(array_map('strval', $usernames))) as $username) {
    $mapping[Pseudonym::legacyActorId($username)] = Pseudonym::actorId($username);
}
echo count($mapping) . " known usernames.\n";

$connection->beginTransaction();
try {
    $total = 0;
    foreach (ACTOR_COLUMNS as $table => $columns) {
        foreach ($columns as $column) {
            if (!isset($existingColumns[$table][$column])) {
                continue;
            }
            $statement = $connection->prepare(sprintf('UPDATE `%s` SET `%s` = :keyed WHERE `%s` = :legacy', $table, $column, $column));
            $changed = 0;
            foreach ($mapping as $legacy => $keyed) {
                $statement->execute(['keyed' => $keyed, 'legacy' => $legacy]);
                $changed += $statement->rowCount();
            }
            if ($changed > 0) {
                echo sprintf("%s.%s: %d rows\n", $table, $column, $changed);
            }
            $total += $changed;
        }
    }
    if ($apply) {
        $connection->commit();
        echo "Rekeyed {$total} values.\n";
    } else {
        $connection->rollBack();
        echo "Dry run: {$total} values would change. Re-run with --apply to write them.\n";
    }
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    fwrite(STDERR, 'Rekeying failed and nothing was changed: ' . $exception->getMessage() . "\n");
    exit(1);
}
