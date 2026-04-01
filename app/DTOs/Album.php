<?php

namespace App\DTOs;

final class Album
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromApiResponse(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
        );
    }
}
