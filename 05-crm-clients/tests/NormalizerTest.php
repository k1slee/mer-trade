<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class NormalizerTest extends TestCase
{
    #[DataProvider('unpProvider')]
    public function testNormalizeUnp(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, Normalizer::normalizeUnp($input));
    }

    public static function unpProvider(): array
    {
        return [
            'чистые 9 цифр' => ['100000001', '100000001'],
            'с пробелами' => ['100 000 001', '100000001'],
            'с дефисами' => ['100-000-001', '100000001'],
            'пусто' => ['', null],
            'null' => [null, null],
            '8 цифр' => ['10000000', null],
            '10 цифр' => ['1000000001', null],
        ];
    }

    #[DataProvider('phoneProvider')]
    public function testNormalizePhone(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, Normalizer::normalizePhone($input));
    }

    public static function phoneProvider(): array
    {
        return [
            '+375 (00) 123-45-01' => ['+375 (00) 123-45-01', '375001234501'],
            '80...11 цифр' => ['80001234501', '375001234501'],
            '8 (000)...' => ['8 (000) 123-45-03', '375001234503'],
            '375...' => ['375001234509', '375001234509'],
            '9 цифр без префикса' => ['001234509', '375001234509'],
            '10 цифр 0...' => ['0001234503', '375001234503'],
            'слишком короткий' => ['12345', null],
            'пусто' => ['', null],
        ];
    }

    #[DataProvider('emailProvider')]
    public function testNormalizeEmail(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, Normalizer::normalizeEmail($input));
    }

    public static function emailProvider(): array
    {
        return [
            'обычный' => ['a@b.example', 'a@b.example'],
            'с пробелами' => ['  a@b.example  ', 'a@b.example'],
            'CAPS' => ['INFO@BETA-TEST.EXAMPLE', 'info@beta-test.example'],
            'невалидный' => ['not-an-email', null],
            'пусто' => ['', null],
        ];
    }

    #[DataProvider('nameProvider')]
    public function testNormalizeName(?string $input, string $expected): void
    {
        $this->assertSame($expected, Normalizer::normalizeName($input));
    }

    public static function nameProvider(): array
    {
        return [
            'ООО в начале' => ['ООО «Альфа-Учебный»', 'альфа учебный'],
            'ООО в конце' => ['Альфа-Учебный ООО', 'альфа учебный'],
            'ЧУП' => ['ЧУП «Бета-Тест»', 'бета тест'],
            'ИП с пробелом' => ['ИП Гамма Учебный', 'гамма учебный'],
            'без юр.формы' => ['Альфа', 'альфа'],
            'пусто' => ['', ''],
            'null' => [null, ''],
        ];
    }

    public function testNameSimilarityIdentical(): void
    {
        $this->assertSame(100.0, Normalizer::nameSimilarityNorm('альфа учебный', 'альфа учебный'));
    }

    public function testNameSimilarityEmpty(): void
    {
        $this->assertSame(0.0, Normalizer::nameSimilarityNorm('', 'альфа'));
    }
}