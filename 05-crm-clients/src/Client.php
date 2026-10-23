<?php

declare(strict_types=1);

final readonly class Client
{
    public function __construct(
        public string $clientId,
        public string $name,
        public ?string $unp,
        public ?string $phone,
        public ?string $email,
        public string $createdAt,
        public ?string $manager,
        public ?string $unpNorm,
        public ?string $phoneNorm,
        public ?string $emailNorm,
        public string $nameNorm,
    ) {
    }

    public function filledFieldsCount(): int
    {
        $count = 0;
        if ($this->unp !== null && $this->unp !== '') {
            $count++;
        }
        if ($this->phone !== null && $this->phone !== '') {
            $count++;
        }
        if ($this->email !== null && $this->email !== '') {
            $count++;
        }

        return $count;
    }
}
