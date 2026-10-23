<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DuplicateDetectorTest extends TestCase
{
    /**
     * Хелпер: собрать Client с нормализованными полями.
     *
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

    // ─────────────────────────── Правила «точно» ───────────────────────────

    public function testExactByUnpAndName(): void
    {
        $a = self::client(['id' => 'A', 'name' => 'ООО «Альфа»', 'unp' => '100000001']);
        $b = self::client(['id' => 'B', 'name' => 'Альфа ООО',   'unp' => '100000001']);

        $result = (new DuplicateDetector())->detect([$a, $b]);

        $this->assertCount(1, $result->groups);
        $this->assertSame('точно', $result->groups[0]->confidenceLabel);
        $this->assertSame(['A', 'B'], $result->groups[0]->clientIds);
    }

    public function testExactByPhoneAndName(): void
    {
        $a = self::client(['id' => 'A', 'name' => 'ООО «Бета»', 'phone' => '+375 (00) 123-45-03']);
        $b = self::client(['id' => 'B', 'name' => 'Бета ООО',   'phone' => '80001234503']);

        $result = (new DuplicateDetector())->detect([$a, $b]);

        $this->assertCount(1, $result->groups);
        $this->assertSame('точно', $result->groups[0]->confidenceLabel);
    }

    public function testSamePhoneAndEmailWithOneUnpMissingIsExact(): void
    {
        // Как C-1022 в реальных данных: УНП нет, но телефон и email совпадают → exact
        $a = self::client([
            'id' => 'A',
            'name' => 'Бета Тест',
            'phone' => '80001234503',
            'email' => 'info@beta-test.example',
        ]);
        $b = self::client([
            'id' => 'B',
            'name' => 'Бета-Тест',
            'unp' => '100000003',
            'phone' => '80001234503',
            'email' => 'info@beta-test.example',
        ]);

        $result = (new DuplicateDetector())->detect([$a, $b]);

        $this->assertCount(1, $result->groups);
        $this->assertSame('точно', $result->groups[0]->confidenceLabel);
    }

    // ──────────────────── Правила «проверить вручную» ────────────────────

    public function testManualBySameUnpButDifferentNames(): void
    {
        $a = self::client(['id' => 'A', 'name' => 'Совсем Разное', 'unp' => '100000001']);
        $b = self::client(['id' => 'B', 'name' => 'Иное Название', 'unp' => '100000001']);

        $result = (new DuplicateDetector())->detect([$a, $b]);

        $this->assertCount(1, $result->groups);
        $this->assertSame('проверить вручную', $result->groups[0]->confidenceLabel);
    }

    public function testHighNameSimilarityWithoutSharedRequisitesGoesToManual(): void
    {
        $a = self::client(['id' => 'A', 'name' => 'Эпсилон-Демо', 'unp' => '100000004', 'phone' => '80001234504']);
        $b = self::client(['id' => 'B', 'name' => 'Ипсилон-Демо', 'unp' => '100000025', 'phone' => '80001234525']);

        $result = (new DuplicateDetector())->detect([$a, $b]);

        $this->assertCount(1, $result->groups);
        $this->assertSame('проверить вручную', $result->groups[0]->confidenceLabel);
    }

    // ────────────────── Правила «похожие, но не дубли» ──────────────────

    public function testSamePhoneDifferentUnpGoesToSimilar(): void
    {
        // Как C-1005 ↔ C-1019: одинаковый телефон, разные УНП, разные названия
        $a = self::client(['id' => 'A', 'name' => 'Зета', 'unp' => '100000005', 'phone' => '80001234505']);
        $b = self::client(['id' => 'B', 'name' => 'Ро',   'unp' => '100000019', 'phone' => '80001234505']);

        $result = (new DuplicateDetector())->detect([$a, $b]);

        $this->assertCount(0, $result->groups);
        $this->assertCount(1, $result->similarNotDuplicates);
        $this->assertSame('A', $result->similarNotDuplicates[0]->clientIdA);
        $this->assertSame('B', $result->similarNotDuplicates[0]->clientIdB);
    }

    public function testUnrelatedClientsProduceNothing(): void
    {
        $a = self::client([
            'id' => 'A', 'name' => 'Альфа',
            'unp' => '100000001', 'phone' => '80001234501', 'email' => 'a@a.example',
        ]);
        $b = self::client([
            'id' => 'B', 'name' => 'Омега',
            'unp' => '100000999', 'phone' => '80001234999', 'email' => 'b@b.example',
        ]);

        $result = (new DuplicateDetector())->detect([$a, $b]);

        $this->assertCount(0, $result->groups);
        $this->assertCount(0, $result->similarNotDuplicates);
    }

    // ────────────── Группировка: транзитивность и weak link ──────────────

    public function testTransitiveChainFormsSingleGroup(): void
    {
        // A ↔ B (exact), B ↔ C (exact) → одна группа из трёх
        $a = self::client(['id' => 'A', 'name' => 'Альфа',   'unp' => '100000001']);
        $b = self::client(['id' => 'B', 'name' => 'Альфа-2', 'unp' => '100000001']);
        $c = self::client(['id' => 'C', 'name' => 'Альфа-3', 'unp' => '100000001']);

        $result = (new DuplicateDetector())->detect([$a, $b, $c]);

        $this->assertCount(1, $result->groups);
        $this->assertCount(3, $result->groups[0]->clientIds);
    }

    public function testWeakLinkFlagFalseForSingleManualEdge(): void
    {
        // Одно manual-ребро, никаких exact-компонентов
        $a = self::client(['id' => 'A', 'name' => 'Совсем Разное', 'unp' => '100000001']);
        $b = self::client(['id' => 'B', 'name' => 'Иное Название', 'unp' => '100000001']);

        $result = (new DuplicateDetector())->detect([$a, $b]);

        $this->assertCount(1, $result->groups);
        $this->assertFalse($result->groups[0]->mergesViaWeakLink);
    }

    public function testWeakLinkFlagTrueWhenManualConnectsTwoExactComponents(): void
    {
        // A↔B exact (по УНП), C↔D exact (по УНП), между ними manual через 100% схожесть названий
        // при разных УНП и разных телефонах → две exact-компоненты соединены слабым звеном.
        $a = self::client(['id' => 'A', 'name' => 'Альфа Компани', 'unp' => '100000001', 'phone' => '80001234501']);
        $b = self::client(['id' => 'B', 'name' => 'Альфа Компани', 'unp' => '100000001', 'phone' => '80001234501']);
        $c = self::client(['id' => 'C', 'name' => 'Альфа Компани', 'unp' => '100000009', 'phone' => '80001234509']);
        $d = self::client(['id' => 'D', 'name' => 'Альфа Компани', 'unp' => '100000009', 'phone' => '80001234509']);

        $result = (new DuplicateDetector())->detect([$a, $b, $c, $d]);

        $this->assertCount(1, $result->groups);
        $this->assertCount(4, $result->groups[0]->clientIds);
        $this->assertTrue($result->groups[0]->mergesViaWeakLink);
    }
}