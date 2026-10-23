<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CsvReaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/csv_test_' . uniqid('', true);
        mkdir($this->tmpDir . '/data', 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/data/*') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->tmpDir . '/data');
        @rmdir($this->tmpDir);
    }

    private function writeClients(string $content): void
    {
        file_put_contents($this->tmpDir . '/data/crm_clients.csv', $content);
        // пустой валидный tickets.csv, чтобы readTickets не упал
        file_put_contents(
            $this->tmpDir . '/data/tickets.csv',
            "ticket_id;client_id;channel;topic;status;created_at\n",
        );
    }

    public function testBomIsStripped(): void
    {
        $this->writeClients(
            "\xEF\xBB\xBFclient_id;name;unp;phone;email;created_at;manager\n" .
            "C-1;ООО «Тест»;100000001;;;2026-01-01;М\n",
        );

        $r = CsvReader::load($this->tmpDir);

        $this->assertCount(1, $r->clients);
        $this->assertSame('C-1', $r->clients[0]->clientId);
    }

    public function testMissingRequiredColumnThrows(): void
    {
        $this->writeClients("client_id;name\nC-1;Тест\n");

        $this->expectException(RuntimeException::class);
        CsvReader::load($this->tmpDir);
    }

    public function testDuplicateClientIdWarns(): void
    {
        $this->writeClients(
            "client_id;name;unp;phone;email;created_at;manager\n" .
            "C-1;А;100000001;;;2026-01-01;М\n" .
            "C-1;Б;100000002;;;2026-01-02;М\n",
        );

        $r = CsvReader::load($this->tmpDir);

        $this->assertCount(1, $r->clients);
        $this->assertNotEmpty($r->warnings);
    }

    public function testReferentialIntegrityWarns(): void
    {
        // 7 полей: client_id;name;unp;phone;email;created_at;manager
        // C-1;А;;;;2026-01-01;М  →  C-1, А, "", "", "", 2026-01-01, М
        $this->writeClients(
            "client_id;name;unp;phone;email;created_at;manager\n" .
            "C-1;А;;;;2026-01-01;М\n",
        );
        file_put_contents(
            $this->tmpDir . '/data/tickets.csv',
            "ticket_id;client_id;channel;topic;status;created_at\n" .
            "T-1;C-999;email;x;new;2026-10-01\n",
        );

        $r = CsvReader::load($this->tmpDir);

        $this->assertNotEmpty($r->warnings);
        $this->assertStringContainsString('C-999', implode(' ', $r->warnings));
    }
}