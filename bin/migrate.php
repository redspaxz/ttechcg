<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Database;
use App\Shared\Infrastructure\Environment;
use App\Shared\Infrastructure\MigrationRunner;

$root = dirname(__DIR__);
require $root . '/bootstrap/autoload.php';
Environment::load($root . '/.env');

$connection = Database::connect(true);
MigrationRunner::run($connection, $root . '/database/migrations');
echo "Database migrations are current.\n";

