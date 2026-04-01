<?php

namespace App\DTOs;

use Carbon\Carbon;

final class Photo
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly Carbon $uploadedAt,
        public readonly ?Carbon $takenAt,
        public readonly ?string $url,
        public readonly array $parentIds,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromApiResponse(array $data): self
    {
        $contentProps = $data['contentProperties'] ?? [];

        $takenAt = null;
        if (isset($contentProps['contentDate'])) {
            try {
                $takenAt = Carbon::parse($contentProps['contentDate']);
            } catch (\Throwable) {
                $takenAt = null;
            }
        }

        $uploadedAt = Carbon::parse($data['createdDate'] ?? 'now');

        $url = $data['tempLink'] ?? null;

        return new self(
            id: $data['id'],
            name: $data['name'],
            uploadedAt: $uploadedAt,
            takenAt: $takenAt,
            url: $url,
            parentIds: $data['parents'] ?? [],
        );
    }

    /**
     * @return array<string, string>
     */
    public function toCsvRow(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'uploaded_at' => $this->uploadedAt->toIso8601String(),
            'taken_at' => $this->takenAt?->toIso8601String() ?? '',
            'url' => $this->url ?? '',
        ];
    }
}
