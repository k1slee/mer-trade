# CRM: поиск дублей клиентов

Учебный CLI-проект на PHP 8.1+ (без Composer): читает CSV клиентов и обращений, находит группы дублей и пишет отчёт.

## Требования

- PHP 8.1 или новее
- Файлы данных (не изменять): `data/crm_clients.csv`, `data/tickets.csv`

## Запуск

Из корня проекта:

```bash
php bin/find-duplicates.php
```

Отчёт выводится в консоль и сохраняется в **`report.txt`** в корне проекта (UTF-8).

Для отладки нормализации полей:

```bash
php bin/dump-normalized.php
```

## Тесты

Запуск:

php phpunit.phar --testdox

Ожидаемый результат:

48 / 48 (100%)
OK (48 tests, 65 assertions)

Что покрыто:

tests/NormalizerTest.php	УНП, телефон, email, название, схожесть
tests/DuplicateDetectorTest.php	правила «точно / проверить вручную / не дубль», транзитивность, weak-link
tests/PickMainClientTest.php	4 уровня приоритета выбора главной карточки
tests/CsvReaderTest.php	BOM, отсутствующие колонки, дубли ID, битые ссылки
tests/ReportSnapshotTest.php	побайтовое совпадение с tests/fixtures/report.expected.txt


## Структура

- `src/` — чтение CSV, нормализация, детектор дублей, печать отчёта
- `bin/find-duplicates.php` — основная точка входа
- `05-design.md` — проектный документ по сущности «Обращение»

При ошибке (нет файла, пустой CSV) скрипт пишет сообщение в stderr и завершается с кодом `1`.
