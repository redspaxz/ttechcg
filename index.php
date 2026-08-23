<?php

declare(strict_types=1);

use App\Shared\Http\Request;

$application = require __DIR__ . '/bootstrap/app.php';
$application->handle(Request::capture())->send();

