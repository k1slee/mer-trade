<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReportSnapshotTest extends TestCase
{
    public function testFullReportMatchesSnapshot(): void
    {
        $fixturePath = __DIR__ . '/fixtures/report.expected.txt';
        if (!is_file($fixturePath)) {
            $this->markTestSkipped(
                'Fixture not found: ' . $fixturePath
                . '. Сгенерируй: php bin/find-duplicates.php > tests/fixtures/report.expected.txt',
            );
        }

        $baseDir = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        $load = CsvReader::load($baseDir);
        $detector = new DuplicateDetector();
        $result = $detector->detect($load->clients);

        $printer = new ReportPrinter();
        ob_start();
        $printer->print(
            $result,
            $load->clients,
            $load->tickets,
            $load->warnings,
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'report.test.txt',
        );
        $actual = (string) ob_get_clean();

        $expected = (string) file_get_contents($fixturePath);

        // Нормализуем переносы строк, чтобы тест не падал на CRLF vs LF
        $actual = str_replace("\r\n", "\n", $actual);
        $expected = str_replace("\r\n", "\n", $expected);

        $this->assertSame($expected, $actual);
    }
}