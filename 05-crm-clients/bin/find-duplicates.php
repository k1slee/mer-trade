<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

$baseDir = dirname(__DIR__);

require $baseDir . '/src/Client.php';
require $baseDir . '/src/Ticket.php';
require $baseDir . '/src/Normalizer.php';
require $baseDir . '/src/CsvReader.php';
require $baseDir . '/src/DuplicateDetector.php';
require $baseDir . '/src/ReportPrinter.php';

try {
    $load = CsvReader::load($baseDir);
    $detector = new DuplicateDetector();
    $result = $detector->detect($load->clients);
    $printer = new ReportPrinter();
    $reportPath = $baseDir . DIRECTORY_SEPARATOR . 'report.txt';
    $printer->print($result, $load->clients, $load->tickets, $load->warnings, $reportPath);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
