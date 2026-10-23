<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

$baseDir = dirname(__DIR__);

require $baseDir . '/src/Client.php';
require $baseDir . '/src/Ticket.php';
require $baseDir . '/src/Normalizer.php';
require $baseDir . '/src/CsvReader.php';

try {
    $load = CsvReader::load($baseDir);
    foreach ($load->clients as $client) {
        echo $client->clientId . PHP_EOL;
        echo '  name_norm: ' . $client->nameNorm . PHP_EOL;
        echo '  unp_norm:  ' . ($client->unpNorm ?? 'null') . PHP_EOL;
        echo '  phone_norm:' . ($client->phoneNorm ?? 'null') . PHP_EOL;
        echo '  email_norm:' . ($client->emailNorm ?? 'null') . PHP_EOL;
        echo PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
