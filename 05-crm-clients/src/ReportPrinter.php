<?php

declare(strict_types=1);

final class ReportPrinter
{
    /**
     * @param list<Client> $clients
     * @param list<Ticket> $tickets
     * @param list<string> $warnings
     */
    public function print(
        DuplicateDetectionResult $detection,
        array $clients,
        array $tickets,
        array $warnings,
        string $reportPath,
    ): void {
        $byId = [];
        foreach ($clients as $client) {
            $byId[$client->clientId] = $client;
        }

        $ticketCounts = [];
        foreach ($tickets as $ticket) {
            if (!isset($ticketCounts[$ticket->clientId])) {
                $ticketCounts[$ticket->clientId] = 0;
            }
            $ticketCounts[$ticket->clientId]++;
        }

        $lines = [];
        $groupNum = 0;
        $exactGroups = 0;
        $manualGroups = 0;
        $touchedClients = [];
        $ticketsSaved = 0;

        foreach ($detection->groups as $group) {
            $groupNum++;
            if ($group->confidenceLabel === 'точно') {
                $exactGroups++;
            } else {
                $manualGroups++;
            }

            $mainId = DuplicateDetector::pickMainClientId($group->clientIds, $byId, $ticketCounts);
            foreach ($group->clientIds as $cid) {
                $touchedClients[$cid] = true;
            }

            $totalTickets = 0;
            foreach ($group->clientIds as $cid) {
                $totalTickets += $ticketCounts[$cid] ?? 0;
            }
            $mainTickets = $ticketCounts[$mainId] ?? 0;
            $moveTickets = $totalTickets - $mainTickets;
            $ticketsSaved += $moveTickets;

            $lines[] = sprintf('=== ГРУППА %d — уверенность: %s ===', $groupNum, $group->confidenceLabel);
            $lines[] = 'Карточки:';
            $sortedMembers = $group->clientIds;
            sort($sortedMembers);
            foreach ($sortedMembers as $cid) {
                $lines[] = '  ' . self::formatClientLine($byId[$cid], $ticketCounts[$cid] ?? 0);
            }
            $lines[] = 'Обоснование:';
            foreach ($group->edges as $edge) {
                [$ea, $eb] = DuplicateDetector::orderedPair($edge->clientIdA, $edge->clientIdB);
                $levelRu = $edge->level === DuplicateEdge::LEVEL_EXACT ? 'точно' : 'проверить вручную';
                $lines[] = sprintf('  %s ↔ %s: %s — %s', $ea, $eb, $levelRu, $edge->reasons[0] ?? '');
            }
            if ($group->mergesViaWeakLink) {
                $lines[] = '  ⚠ группы сливаются через слабое звено';
            }
            $lines[] = 'Главная карточка: ' . $mainId . ' — ' . self::mainCardReason($mainId, $group->clientIds, $byId, $ticketCounts);
            $lines[] = sprintf('Перейдёт обращений на главную: %d (всего в группе: %d)', $moveTickets, $totalTickets);
            $lines[] = '';
        }

        $lines[] = '=== ПОХОЖИЕ, НО НЕ ДУБЛИ ===';
        if ($detection->similarNotDuplicates === []) {
            $lines[] = '  (нет)';
        } else {
            foreach ($detection->similarNotDuplicates as $pair) {
                $lines[] = sprintf('  %s ↔ %s: %s', $pair->clientIdA, $pair->clientIdB, $pair->description);
            }
        }
        $lines[] = '';

        $clientsWithoutTickets = 0;
        foreach ($clients as $client) {
            if (($ticketCounts[$client->clientId] ?? 0) === 0) {
                $clientsWithoutTickets++;
            }
        }

        $lines[] = '=== СВОДКА ===';
        $lines[] = sprintf(
            'Групп дублей: %d (из них «точно»: %d, «проверить вручную»: %d)',
            count($detection->groups),
            $exactGroups,
            $manualGroups,
        );
        $lines[] = 'Карточек затронуто: ' . count($touchedClients);
        $lines[] = sprintf('Обращений «спасается» после объединения: %d', $ticketsSaved);
        $lines[] = 'Предупреждения по данным:';
        self::appendWarningSection($lines, $warnings, $clientsWithoutTickets);

        $text = implode("\n", $lines) . "\n";
        file_put_contents($reportPath, $text);
        echo $text;
    }

    private static function formatClientLine(Client $c, int $ticketCount): string
    {
        $phone = $c->phoneNorm ?? '—';
        $email = $c->emailNorm ?? '—';
        $unp = $c->unpNorm ?? '—';

        return sprintf(
            '%s  %s   УНП %s  тел %s  %s  создана %s  %s  обращений: %d',
            $c->clientId,
            $c->name,
            $unp,
            $phone,
            $email,
            $c->createdAt,
            $c->manager ?? '—',
            $ticketCount,
        );
    }

    /**
     * @param list<string> $memberIds
     * @param array<string, Client> $byId
     * @param array<string, int> $ticketCounts
     */
    private static function mainCardReason(string $mainId, array $memberIds, array $byId, array $ticketCounts): string
    {
        $main = $byId[$mainId];
        $reasons = [];
        $others = array_filter($memberIds, static fn (string $id): bool => $id !== $mainId);

        $mainT = $ticketCounts[$mainId] ?? 0;
        $maxOtherT = 0;
        foreach ($others as $oid) {
            $maxOtherT = max($maxOtherT, $ticketCounts[$oid] ?? 0);
        }
        if ($mainT > $maxOtherT) {
            $reasons[] = sprintf('больше обращений (%d vs %d)', $mainT, $maxOtherT);
        }

        $mainF = $main->filledFieldsCount();
        $maxOtherF = 0;
        foreach ($others as $oid) {
            $maxOtherF = max($maxOtherF, $byId[$oid]->filledFieldsCount());
        }
        if ($mainF > $maxOtherF) {
            $reasons[] = 'полнее данные';
        }

        $isOldest = true;
        foreach ($others as $oid) {
            if (strcmp($byId[$oid]->createdAt, $main->createdAt) < 0) {
                $isOldest = false;
                break;
            }
        }
        if ($isOldest && $others !== []) {
            $reasons[] = 'старше';
        }

        if ($reasons === []) {
            $reasons[] = 'меньший client_id (детерминизм)';
        }

        return implode(', ', $reasons);
    }

    /**
     * @param list<string> $lines
     * @param list<string> $warnings
     */
    private static function appendWarningSection(array &$lines, array $warnings, int $clientsWithoutTickets): void
    {
        $missingClients = [];
        $dupClients = [];
        $dupTickets = [];
        $shortRows = [];

        foreach ($warnings as $w) {
            if (str_starts_with($w, 'несуществующие client_id')) {
                $missingClients[] = $w;
            } elseif (str_starts_with($w, 'дубли client_id')) {
                $dupClients[] = preg_replace('/^дубли client_id в crm_clients\.csv: /', '', $w) ?? $w;
            } elseif (str_starts_with($w, 'дубли ticket_id')) {
                $dupTickets[] = preg_replace('/^дубли ticket_id в tickets\.csv: /', '', $w) ?? $w;
            } elseif (str_contains($w, 'недостающее число колонок')) {
                $shortRows[] = $w;
            } else {
                $shortRows[] = $w;
            }
        }

        $lines[] = '  - несуществующие client_id в tickets.csv: ' . ($missingClients !== [] ? self::extractList($missingClients[0]) : '—');
        $lines[] = '  - дубли client_id в crm_clients.csv: ' . ($dupClients !== [] ? implode(', ', $dupClients) : '—');
        $lines[] = '  - дубли ticket_id в tickets.csv: ' . ($dupTickets !== [] ? implode(', ', $dupTickets) : '—');
        $lines[] = '  - строки с недостающим числом колонок: ' . ($shortRows !== [] ? implode('; ', $shortRows) : '—');
        $lines[] = '  - клиенты без единого обращения: ' . $clientsWithoutTickets;
    }

    private static function extractList(string $warning): string
    {
        $pos = strpos($warning, ': ');
        if ($pos === false) {
            return $warning;
        }

        return substr($warning, $pos + 2);
    }
}
