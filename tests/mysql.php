<?php

declare(strict_types=1);

/*
 * Opt-in MySQL integration checks for the CRM and pickup-sheet SQL paths that tests/run.php can
 * only inspect as source text. It DROPS EVERY TABLE in the target database, so it only runs against
 * a database whose name ends in "_test".
 *
 *   TEST_MYSQL_DSN="mysql:host=127.0.0.1;port=3306;dbname=pickupsheet_test;charset=utf8mb4" \
 *   TEST_MYSQL_USER=root TEST_MYSQL_PASSWORD=secret php tests/mysql.php
 */

use App\Modules\CRM\Application\CustomerConsignorDirectory;
use App\Modules\CRM\Application\CustomerService;
use App\Modules\CRM\Application\DuplicateCustomerException;
use App\Modules\CRM\Infrastructure\MysqlCustomerRepository;
use App\Modules\Pickupsheet\Application\AwbReuseException;
use App\Modules\Pickupsheet\Application\PickupSheetService;
use App\Modules\Pickupsheet\Infrastructure\MysqlPickupSheetRepository;
use App\Shared\Infrastructure\MigrationRunner;

require dirname(__DIR__) . '/bootstrap/autoload.php';

$dsn = (string) getenv('TEST_MYSQL_DSN');
if ($dsn === '') {
    fwrite(STDERR, "Skipped: set TEST_MYSQL_DSN (plus TEST_MYSQL_USER and TEST_MYSQL_PASSWORD) to run the MySQL checks.\n");
    exit(0);
}
if (preg_match('/dbname=([A-Za-z0-9_]+_test)(?:;|$)/', $dsn, $databaseMatch) !== 1) {
    fwrite(STDERR, "Refused: the database name in TEST_MYSQL_DSN must end in _test because every table in it is dropped.\n");
    exit(1);
}

$pdo = new PDO($dsn, (string) getenv('TEST_MYSQL_USER'), (string) getenv('TEST_MYSQL_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ENGINE_SUBSTITUTION'");
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $pdo->exec('DROP TABLE `' . str_replace('`', '``', (string) $table) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
    $failures += $condition ? 0 : 1;
};
$migrations = dirname(__DIR__) . '/database/migrations';
$runMigrationsBefore = static function (string $firstExcluded) use ($pdo, $migrations): void {
    $directory = sys_get_temp_dir() . '/pickupsheet-migrations-' . getmypid() . '-' . $firstExcluded;
    @mkdir($directory);
    foreach (glob($migrations . '/*.sql') ?: [] as $file) {
        if (basename($file) < $firstExcluded) {
            copy($file, $directory . '/' . basename($file));
        }
    }
    MigrationRunner::run($pdo, $directory);
};
$actor = str_repeat('a', 24);
$pickups = new PickupSheetService(new MysqlPickupSheetRepository($pdo));
$line = static fn (string $consignor, string $awb, string $weight = '1'): array => [
    'consignor' => $consignor, 'awb_number' => $awb, 'destination' => 'DLA', 'amount' => '1000',
    'pieces' => '1', 'weight_kg' => $weight, 'checked_by' => 'Checker',
];
$submit = static fn (array $rows): string => $pickups->submit([
    'agent_name' => 'Integration Agent', 'collection_date' => '2026-09-20', 'privacy_consent' => '1', 'shipments' => $rows,
])->referenceNumber;
$crm = static fn (): CustomerService => new CustomerService(new MysqlCustomerRepository($pdo));

// Migration 018 on data the old byte-wise sync produced.
$runMigrationsBefore('018');
$legacyInsert = $pdo->prepare("INSERT INTO pickup_customers (customer_key, display_name, email, notes, status, source) VALUES (SHA2(LOWER(?), 256), ?, ?, ?, 'active', 'shipment')");
$legacyInsert->execute(['Société Générale', 'Société Générale', null, 'Primary note']);
$legacyInsert->execute(['Societe Generale', 'Societe Generale', 'sg@example.com', 'Duplicate note']);
$pdo->exec("INSERT INTO pickup_customer_reward_adjustments (customer_key, points_delta, reason, actor_id) VALUES (SHA2(LOWER('Societe Generale'), 256), 15, 'Legacy bonus', REPEAT('a', 24))");
$runMigrationsBefore('020');
$folded = $pdo->query('SELECT display_name, email FROM pickup_customers')->fetchAll();
$check(count($folded) === 1 && $folded[0]['display_name'] === 'Société Générale' && $folded[0]['email'] === 'sg@example.com', 'migration 018 folds accent duplicates and keeps their contact details');

// Migration 020 on spacing variants.
$spacedRef = $submit([$line('Mboa Logistics', '1111111110'), $line('Mboa Logistics', '1111111111')]);
$pdo->exec("UPDATE pickup_shipments SET consignor = 'Mboa  Logistics' WHERE awb_number = '1111111111'");
$crm()->synchronize();
MigrationRunner::run($pdo, $migrations);
$check((int) $pdo->query("SELECT COUNT(*) FROM pickup_customers WHERE display_name LIKE 'Mboa%'")->fetchColumn() === 1, 'migration 020 folds spacing variants into one profile');
$check($pdo->query("SHOW INDEX FROM pickup_shipments WHERE Key_name = 'pickup_shipments_consignor_idx'")->fetch() !== false, 'migration 021 indexes shipment consignors');

// Rename reaches every sheet and is audited.
$mboa = $crm()->existingCustomer('Mboa Logistics')['customer'];
$crm()->save($mboa->customerKey, ['display_name' => 'Mboa Logistics SARL', 'status' => 'active'], $actor);
$renamed = array_unique(array_map(static fn ($shipment): string => $shipment->consignor, $pickups->findByReference($spacedRef)->shipments));
$check($renamed === ['Mboa Logistics SARL'], 'a rename updates every shipment on existing sheets');
$audit = $pdo->query("SELECT after_snapshot FROM pickup_sheet_edit_audit WHERE after_snapshot LIKE '%crm_customer_rename%'")->fetchColumn();
$check(is_string($audit) && str_contains($audit, 'Mboa Logistics SARL'), 'a rename writes a pickup-sheet audit entry');

// Sync: accent variants in one batch, renamed-key collisions, incremental reads.
$renamedCustomer = $crm()->save(null, ['display_name' => 'Acme Trading', 'status' => 'active'], $actor);
$crm()->save($renamedCustomer->customerKey, ['display_name' => 'Acme Tradings', 'status' => 'active'], $actor);
$submit([$line('Café Mboa', '2222222220'), $line('Cafe Mboa', '2222222221'), $line('Acme Trading', '2222222222')]);
$crm()->synchronize();
$check((int) $pdo->query("SELECT COUNT(*) FROM pickup_customers WHERE display_name = 'Cafe Mboa'")->fetchColumn() === 1, 'sync creates one profile for accent variants in the same batch');
$acmeKey = $pdo->query("SELECT customer_key FROM pickup_customers WHERE display_name = 'Acme Trading'")->fetchColumn();
$check(is_string($acmeKey) && $acmeKey !== $renamedCustomer->customerKey, 'sync avoids a key still held by a renamed profile');
$check((int) $pdo->query("SELECT last_shipment_id FROM pickup_crm_sync_state WHERE sync_name = 'shipments'")->fetchColumn() === (int) $pdo->query('SELECT MAX(id) FROM pickup_shipments')->fetchColumn(), 'sync records the last shipment it processed');

// Merge, alias resolution, undo.
$target = $crm()->existingCustomer('Acme Tradings')['customer'];
$source = $crm()->existingCustomer('Acme Trading')['customer'];
$crm()->adjustRewards($source->customerKey, 'bonus', '9', 'Source bonus', $actor);
$crm()->addActivity($source->customerKey, ['activity_type' => 'call', 'summary' => 'Called the duplicate'], $actor, 'Integration Admin');
$merged = $crm()->merge($target->customerKey, $source->customerKey, $actor);
$check($merged->shipmentCount === 1 && $merged->rewardAdjustmentPoints === 9 && count($crm()->activities($target->customerKey)) === 1, 'merge moves shipments, rewards, and activity');
$submit([$line('acme trading', '3333333330')]);
$crm()->synchronize();
$check($crm()->find($source->customerKey) === null && $crm()->find($target->customerKey)?->shipmentCount === 2, 'new shipments under a merged-away name resolve to the kept profile');
$check((int) $pdo->query("SELECT COUNT(*) FROM pickup_sheet_edit_audit WHERE after_snapshot LIKE '%crm_alias_resolution%'")->fetchColumn() === 1, 'alias resolution is audited');
try {
    $crm()->save(null, ['display_name' => 'Acme Trading', 'status' => 'lead'], $actor);
    $check(false, 'a merged-away name cannot be reused');
} catch (DuplicateCustomerException) {
    $check(true, 'a merged-away name cannot be reused');
}
$restored = $crm()->undoMerge((string) $crm()->recentMerges()[0]['id'], $actor);
$check($restored->shipmentCount === 1 && $restored->rewardAdjustmentPoints === 9 && count($crm()->activities($source->customerKey)) === 1, 'undo restores shipments, rewards, and activity');
$crm()->merge($target->customerKey, $source->customerKey, $actor);
$repeatedMergeId = (string) $crm()->recentMerges()[0]['id'];
$crm()->dismissMerge($repeatedMergeId, $actor);
try {
    $crm()->undoMerge($repeatedMergeId, $actor);
    $check(false, 'an ignored merge leaves Recent merges and cannot be undone');
} catch (InvalidArgumentException) {
    $check($crm()->recentMerges() === [], 'an ignored merge leaves Recent merges and cannot be undone');
}
$directory = $crm()->paginated(['search' => 'acme trading'], 1, 10)['items'];
$check(count($directory) === 1 && $directory[0]->customerKey === $target->customerKey, 'directory search finds a customer by a merged-away name');
$check($crm()->paginated(['search' => '%'], 1, 10)['totalRecords'] === 0, 'directory search treats % literally');

// Rewards after a deleted sheet.
$rewardRef = $submit([$line('Shortfall Traders', '4444444440', '5')]);
$crm()->synchronize();
$shortfall = $crm()->existingCustomer('Shortfall Traders')['customer'];
$crm()->adjustRewards($shortfall->customerKey, 'redeem', '50', 'Redeemed', $actor);
$pickups->delete($rewardRef, $actor);
$afterBonus = $crm()->adjustRewards($shortfall->customerKey, 'bonus', '20', 'Recovery', $actor, 'Integration Admin');
$check($afterBonus->rewardShortfall() === 30 && $afterBonus->rewardBalance() === 0, 'a bonus is accepted and first covers a points shortfall');
$pointsHistory = $crm()->paginatedRewardHistory($shortfall->customerKey, 1, 10);
$check($pointsHistory['totalRecords'] === 2 && $pointsHistory['items'][0]['pointsDelta'] === 20 && $pointsHistory['items'][0]['actorName'] === 'Integration Admin' && $pointsHistory['items'][1]['pointsDelta'] === -50, 'points history lists bonuses and redemptions together, newest first, with names');

// Activities, owners, optimistic locking, deletion.
$lead = $crm()->save(null, ['display_name' => 'Lead Only Company', 'status' => 'lead', 'next_follow_up_on' => '2026-01-10'], $actor);
$logged = $crm()->addActivity($lead->customerKey, ['activity_type' => 'visit', 'occurred_on' => '2026-01-10', 'summary' => 'Visited the office', 'next_follow_up_on' => ''], $actor, 'Integration Admin');
$check($logged->nextFollowUpOn === null, 'logging an activity with no next follow-up closes it');
$owned = $crm()->save($lead->customerKey, ['display_name' => 'Lead Only Company', 'status' => 'lead', 'owner_actor_id' => str_repeat('c', 24), 'owner_name' => 'Owner Person'], $actor);
$check($owned->assignedName === 'Owner Person' && $crm()->paginated(['owner' => str_repeat('c', 24)], 1, 10)['totalRecords'] === 1, 'customers can be assigned and filtered by owner');
try {
    $crm()->save($lead->customerKey, ['display_name' => 'Lead Only Company', 'status' => 'active', 'expected_updated_at' => '2000-01-01 00:00:00'], $actor);
    $check(false, 'a stale save is refused');
} catch (InvalidArgumentException $exception) {
    $check(str_contains($exception->getMessage(), 'changed by someone else'), 'a stale save is refused');
}
$leadPickups = new PickupSheetService(new MysqlPickupSheetRepository($pdo), new CustomerConsignorDirectory(new MysqlCustomerRepository($pdo)));
$check(in_array('Lead Only Company', $leadPickups->consignorSuggestions('Lead', 10), true), 'pickup suggestions include CRM customers with no sheets');
$crm()->delete($shortfall->customerKey, $actor);
$crm()->synchronize();
$check($crm()->existingCustomer('Shortfall Traders') === null && (int) $pdo->query("SELECT COUNT(*) FROM pickup_customer_reward_adjustments WHERE reason = 'Recovery'")->fetchColumn() === 0, 'deleting a customer erases it and is not undone by the next sync');

// Duplicates by phone number, UTF-8-safe merged notes.
$left = $crm()->save(null, ['display_name' => 'Kribi Fisheries', 'phone' => '677 123 456', 'notes' => str_repeat('é', 995), 'status' => 'active'], $actor);
$right = $crm()->save(null, ['display_name' => 'KF Seafood Export', 'phone' => '677123456', 'notes' => str_repeat('ü', 20), 'status' => 'active'], $actor);
$phonePair = array_filter($crm()->duplicateSuggestions(20), static fn (array $suggestion): bool => $suggestion['reason'] === 'phone');
$check($phonePair !== [], 'profiles sharing a phone number are suggested as duplicates');
$notesMerged = $crm()->merge($left->customerKey, $right->customerKey, $actor);
$check(strlen($notesMerged->notes) <= 2000 && preg_match('//u', $notesMerged->notes) === 1, 'merged notes stay valid UTF-8 within 2,000 bytes');

// AWB reuse window.
$submit([$line('AWB Window Customer', '8880000001')]);
try {
    $submit([$line('AWB Window Customer', '8880000002'), $line('AWB Window Customer', '8880000001')]);
    $check(false, 'an AWB already used within 90 days stops the save');
} catch (AwbReuseException $exception) {
    $check($exception->awbNumbers() === ['8880000001'], 'an AWB already used within 90 days stops the save');
}
$confirmedReference = $pickups->submit([
    'agent_name' => 'Integration Agent', 'collection_date' => '2026-09-20', 'privacy_consent' => '1',
    'shipments' => [$line('AWB Window Customer', '8880000002'), $line('AWB Window Customer', '8880000001')],
    'confirmed_awb_reuse' => '8880000001',
])->referenceNumber;
$check($pickups->findByReference($confirmedReference)?->shipmentCount() === 2, 'a confirmed reissued AWB saves');
$outside = $pickups->submit([
    'agent_name' => 'Integration Agent', 'collection_date' => '2027-01-15', 'privacy_consent' => '1',
    'shipments' => [$line('AWB Window Customer', '8880000001')],
]);
$check($outside->collectionDate === '2027-01-15', 'an AWB outside the reuse window saves without a warning');

echo $failures === 0 ? "All MySQL integration checks passed.\n" : "{$failures} MySQL integration check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
