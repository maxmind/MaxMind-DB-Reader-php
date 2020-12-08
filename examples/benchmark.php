<?php

require __DIR__ . '/../autoload.php';

use MaxMind\Db\Reader;

srand(0);

$reader = new Reader('GeoIP2-City.mmdb');
$count = 50000;
$startTime = microtime(true);
for ($i = 0; $i < $count; ++$i) {
    $ip = long2ip(rand(0, 2 ** 32 - 1));
    $t = $reader->get($ip);
    if ($i % 1000 === 0) {
        echo $i . ' ' . $ip . "\n";
        // print_r($t);
    }
}
$endTime = microtime(true);

$duration = $endTime - $startTime;
echo 'Requests per second: ' . $count / $duration . "\n";
