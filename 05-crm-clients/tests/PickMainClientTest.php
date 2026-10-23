<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PickMainClientTest extends TestCase
{
    /**
     * @param array<string, mixed> $override
     */
    private static function client(array $override = []): Client
    {
        $defaults = [
            'id' => 'C-0001',
            'name' => 'ООО «Тест»',
            'unp' => null,
            'phone' => null,
            'email' => null,
            'created' => '2026-01-01',
            'manager' => 'Менеджер',
        ];
        $d = array_merge($defaults, $override);

        return new Client(
            $d['id'],
            $d['name'],
            $d['unp'],
            $d['phone'],
            $d['email'],
            $d['created'],
            $d['manager'],
            Normalizer::normalizeUnp($d['unp']),
            Normalizer::normalizePhone($d['phone']),
            Normalizer::normalizeEmail($d['email']),
            Normalizer::normalizeName($d['name']),
        );
    }

    public function testPickMainByTicketCount(): void
    {
        $a = self::client(['id' => 'C-0001', 'created' => '2026-01-01']);
        $b = self::client(['id' => 'C-0002', 'created' => '2026-01-01']);
        $byId = ['C-0001' => $a, 'C-0002' => $b];
        $counts = ['C-0001' => 5, 'C-0002' => 1];

        $this->assertSame(
            'C-0001',
            DuplicateDetector::pickMainClientId(['C-0001', 'C-0002'], $byId, $counts),
        );
    }

    public function testPickMainByFilledFieldsWhenTicketsEqual(): void
    {
        $a = self::client(['id' => 'C-0001', 'unp' => null, 'phone' => null]);
        $b = self::client([
            'id' => 'C-0002',
            'unp' => '100000001',
            'phone' => '80001234501',
            'email' => 'x@x.example',
        ]);
        $byId = ['C-0001' => $a, 'C-0002' => $b];
        $counts = ['C-0001' => 2, 'C-0002' => 2];

        $this->assertSame(
            'C-0002',
            DuplicateDetector::pickMainClientId(['C-0001', 'C-0002'], $byId, $counts),
        );
    }

    public function testPickMainByCreatedAtWhenEverythingEqual(): void
    {
        $a = self::client(['id' => 'C-0001', 'created' => '2026-05-01']);
        $b = self::client(['id' => 'C-0002', 'created' => '2026-01-01']);
        $byId = ['C-0001' => $a, 'C-0002' => $b];
        $counts = ['C-0001' => 0, 'C-0002' => 0];

        $this->assertSame(
            'C-0002',
            DuplicateDetector::pickMainClientId(['C-0001', 'C-0002'], $byId, $counts),
        );
    }

    public function testPickMainBySmallestIdAsTiebreaker(): void
    {
        $a = self::client(['id' => 'C-0002', 'created' => '2026-01-01']);
        $b = self::client(['id' => 'C-0001', 'created' => '2026-01-01']);
        $byId = ['C-0001' => $b, 'C-0002' => $a];
        $counts = [];

        $this->assertSame(
            'C-0001',
            DuplicateDetector::pickMainClientId(['C-0002', 'C-0001'], $byId, $counts),
        );
    }
}