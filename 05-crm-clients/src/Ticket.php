<?php

declare(strict_types=1);

final readonly class Ticket
{
    public function __construct(
        public string $ticketId,
        public string $clientId,
        public ?string $channel,
        public ?string $topic,
        public ?string $status,
        public ?string $createdAt,
    ) {
    }
}
