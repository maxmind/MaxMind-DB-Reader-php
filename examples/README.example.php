<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use MaxMind\Db\Reader;

$ipAddress = '24.24.24.24';
$databaseFile = 'GeoIP2-City.mmdb';

$reader = new Reader($databaseFile);

print_r($reader->get($ipAddress));

$reader->close();
