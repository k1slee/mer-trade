# Сущность «Обращение» (Ticket) — проектный документ

## Поля

| Поле | Тип / формат | Назначение |
|------|----------------|------------|
| id | TK-XXXX или UUID | Первичный ключ |
| client_id | FK → Клиент | Владелец обращения |
| channel | enum | Канал поступления |
| topic | string | Краткая тема |
| description | text | Полный текст |
| status | enum | Текущий статус |
| priority | enum | critical / high / normal / low |
| assigned_to | FK → Пользователь | Исполнитель |
| created_at | datetime | Создание |
| first_response_at | datetime, nullable | Первый ответ клиенту |
| resolved_at | datetime, nullable | Решено |
| closed_at | datetime, nullable | Закрыто |
| sla_due_at | datetime, nullable | Крайний срок SLA |
| source_external_id | string, nullable | Идемпотентность (сайт / 1С) |
| parent_ticket_id | FK → Ticket, nullable | Слияния / сплиты |
| order_id | FK → Заказ, nullable | Связанный заказ |

## Статусы и переходы

| Из | В | Инициатор |
|----|---|-----------|
| new | in_progress | исполнитель / система (автоназначение) |
| in_progress | waiting_client | исполнитель |
| waiting_client | in_progress | исполнитель / клиент (ответ) |
| in_progress | waiting_internal | исполнитель |
| waiting_internal | in_progress | внутренний отдел |
| in_progress, waiting_* | resolved | исполнитель |
| resolved | closed | исполнитель / клиент (подтверждение) |
| resolved | in_progress | исполнитель / клиент (reopen) |
| любой | cancelled | руководитель / система (спам, дубль) |

## Каналы и source_external_id

| Канал | Что хранить в source_external_id |
|-------|----------------------------------|
| email | Message-ID письма |
| phone | ID записи АТС / call_id |
| site_form | UUID заявки с сайта |
| 1c | GUID документа 1С |
| telegram | chat_id + message_id |
| whatsapp | wa_message_id |
| office | номер бумажного журнала / внутренний seq |
| api | idempotency-key запроса |

## SLA и эскалация

Календарь: Пн–Пт 9:00–18:00 (Europe/Minsk). SLA считается в рабочих минутах/днях.

**First response:** critical 15 мин, high 1 ч, normal 4 ч, low 1 раб. день.

**Resolution:** critical 4 ч, high 8 ч, normal 2 раб. дня, low 5 раб. дней.

**Пауза SLA:** при `waiting_client` — таймер на паузе; при `waiting_internal` — не на паузе.

**Эскалация:** 80% SLA — уведомление исполнителю; breach — руководителю группы; повторный breach по тому же тикету — руководителю отдела.

## Связи

- **Клиент** — many-to-one (обязательный client_id).
- **Заказ** — many-to-one, nullable (order_id).
- **Документы 1С** — one-to-many через `ticket_documents` (type, 1c_ref, added_at).
- **Комментарии / история** — one-to-many (audit trail статусов и полей).
- **Вложения** — one-to-many (файлы, сканы).

## Предотвращение дублей клиентов на входе

| Источник | Поведение |
|----------|-----------|
| **Сайт** | Нормализация УНП/телефона/email на форме; поиск по трём полям; при одном кандидате — привязка тикета; при нескольких — очередь ручной проверки. |
| **1С** | У клиента `1c_id` (GUID) + уникальный индекс `(source, source_external_id)`; обновления только по 1c_id; если GUID новый — поиск по УНП, затем название + телефон/email; конфликт → лог, ручное слияние, автосоздание запрещено. |
| **Ручной ввод** | Перед сохранением — поиск кандидатов, список с предупреждением; подтверждение или слияние; запрет создания при точном совпадении УНП без override. |

**Общие меры:** колонки `unp_norm`, `phone_norm`, `email_norm` с индексами; процедура `merge` (golden record, перенос тикетов/заказов/документов, alias `merged_from_id → master_id`, soft-delete, audit); фоновый периодический поиск дублей; fuzzy по названию — только предупреждение, без автослияния.
