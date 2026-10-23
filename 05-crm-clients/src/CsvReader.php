<?php

declare(strict_types=1);

final class CsvLoadResult
{
    /**
     * @param list<Client> $clients
     * @param list<Ticket> $tickets
     * @param list<string> $warnings
     */
    public function __construct(
        public array $clients,
        public array $tickets,
        public array $warnings,
    ) {
    }
}

final class CsvReader
{
    public static function load(string $baseDir): CsvLoadResult
    {
        $clientsPath = $baseDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'crm_clients.csv';
        $ticketsPath = $baseDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tickets.csv';

        $warnings = [];
        $clients = self::readClients($clientsPath, $warnings);
        $tickets = self::readTickets($ticketsPath, $warnings);
        self::checkReferentialIntegrity($clients, $tickets, $warnings);

        return new CsvLoadResult($clients, $tickets, $warnings);
    }

    /**
     * @param list<string> $warnings
     * @return list<Client>
     */
    private static function readClients(string $path, array &$warnings): array
    {
        $rows = self::readCsvFile($path, 'crm_clients.csv');
        $header = array_shift($rows);
        if ($header === null) {
            throw new RuntimeException('Файл crm_clients.csv пуст или не содержит заголовок.');
        }
        $map = self::buildColumnMap($header);
        self::requireColumns($map, ['client_id', 'name', 'unp', 'phone', 'email', 'created_at', 'manager'], 'crm_clients.csv');

        $clients = [];
        $seenIds = [];
        $lineNum = 1;
        foreach ($rows as $row) {
            $lineNum++;
            if (self::isEmptyRow($row)) {
                continue;
            }
            if (count($row) < count($header)) {
                $warnings[] = "crm_clients.csv строка {$lineNum}: недостающее число колонок";
                continue;
            }
            $clientId = self::cell($row, $map, 'client_id');
            if ($clientId === null || $clientId === '') {
                $warnings[] = "crm_clients.csv строка {$lineNum}: пустой client_id";
                continue;
            }
            if (isset($seenIds[$clientId])) {
                $warnings[] = "дубли client_id в crm_clients.csv: {$clientId}";
                continue;
            }
            $seenIds[$clientId] = true;

            $name = self::cell($row, $map, 'name') ?? '';
            $unp = self::cell($row, $map, 'unp');
            $phone = self::cell($row, $map, 'phone');
            $email = self::cell($row, $map, 'email');
            $createdAt = self::cell($row, $map, 'created_at') ?? '';
            $manager = self::cell($row, $map, 'manager');

            $clients[] = new Client(
                $clientId,
                $name,
                $unp,
                $phone,
                $email,
                $createdAt,
                $manager,
                Normalizer::normalizeUnp($unp),
                Normalizer::normalizePhone($phone),
                Normalizer::normalizeEmail($email),
                Normalizer::normalizeName($name),
            );
        }

        if ($clients === []) {
            throw new RuntimeException('В crm_clients.csv нет ни одной записи клиента.');
        }

        return $clients;
    }

    /**
     * @param list<string> $warnings
     * @return list<Ticket>
     */
    private static function readTickets(string $path, array &$warnings): array
    {
        $rows = self::readCsvFile($path, 'tickets.csv');
        $header = array_shift($rows);
        if ($header === null) {
            throw new RuntimeException('Файл tickets.csv пуст или не содержит заголовок.');
        }
        $map = self::buildColumnMap($header);
        self::requireColumns($map, ['ticket_id', 'client_id', 'channel', 'topic', 'status', 'created_at'], 'tickets.csv');

        $tickets = [];
        $seenIds = [];
        $lineNum = 1;
        foreach ($rows as $row) {
            $lineNum++;
            if (self::isEmptyRow($row)) {
                continue;
            }
            if (count($row) < count($header)) {
                $warnings[] = "tickets.csv строка {$lineNum}: недостающее число колонок";
                continue;
            }
            $ticketId = self::cell($row, $map, 'ticket_id');
            if ($ticketId === null || $ticketId === '') {
                continue;
            }
            if (isset($seenIds[$ticketId])) {
                $warnings[] = "дубли ticket_id в tickets.csv: {$ticketId}";
                continue;
            }
            $seenIds[$ticketId] = true;

            $clientId = self::cell($row, $map, 'client_id') ?? '';
            $tickets[] = new Ticket(
                $ticketId,
                $clientId,
                self::cell($row, $map, 'channel'),
                self::cell($row, $map, 'topic'),
                self::cell($row, $map, 'status'),
                self::cell($row, $map, 'created_at'),
            );
        }

        return $tickets;
    }

    /**
     * @param list<Client> $clients
     * @param list<Ticket> $tickets
     * @param list<string> $warnings
     */
    private static function checkReferentialIntegrity(array $clients, array $tickets, array &$warnings): void
    {
        $clientIds = [];
        foreach ($clients as $client) {
            $clientIds[$client->clientId] = true;
        }
        $missing = [];
        foreach ($tickets as $ticket) {
            if ($ticket->clientId !== '' && !isset($clientIds[$ticket->clientId])) {
                $missing[$ticket->clientId] = true;
            }
        }
        if ($missing !== []) {
            $list = implode(', ', array_keys($missing));
            $warnings[] = "несуществующие client_id в tickets.csv: {$list}";
        }
    }

    /**
     * @return list<list<string|null>>
     */
    private static function readCsvFile(string $path, string $label): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException("Файл не найден или недоступен: {$label} ({$path})");
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException("Не удалось открыть файл: {$label}");
        }

        $rows = [];
        $isFirst = true;
        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            if ($row === [null] || ($row === [])) {
                continue;
            }
            if ($isFirst && isset($row[0])) {
                $row[0] = self::stripBom((string) $row[0]);
                $isFirst = false;
            } elseif ($isFirst) {
                $isFirst = false;
            }
            $normalized = [];
            foreach ($row as $cell) {
                if ($cell === null || $cell === '') {
                    $normalized[] = null;
                } else {
                    $normalized[] = trim((string) $cell) === '' ? null : trim((string) $cell);
                }
            }
            $rows[] = $normalized;
        }
        fclose($fh);

        if ($rows === []) {
            throw new RuntimeException("Файл {$label} пуст.");
        }

        return $rows;
    }

    private static function stripBom(string $value): string
    {
        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            return substr($value, 3);
        }

        return $value;
    }

    /**
     * @param list<string|null> $header
     * @return array<string, int>
     */
    private static function buildColumnMap(array $header): array
    {
        $map = [];
        foreach ($header as $i => $name) {
            if ($name === null) {
                continue;
            }
            $key = mb_strtolower(trim($name), 'UTF-8');
            $map[$key] = $i;
        }

        return $map;
    }

    /**
     * @param array<string, int> $map
     * @param list<string> $required
     */
    private static function requireColumns(array $map, array $required, string $file): void
    {
        foreach ($required as $col) {
            if (!isset($map[$col])) {
                throw new RuntimeException("В {$file} отсутствует обязательная колонка: {$col}");
            }
        }
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $map
     */
    private static function cell(array $row, array $map, string $column): ?string
    {
        if (!isset($map[$column])) {
            return null;
        }
        $idx = $map[$column];

        return $row[$idx] ?? null;
    }

    /**
     * @param list<string|null> $row
     */
    private static function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
