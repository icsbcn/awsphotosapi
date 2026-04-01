<?php

namespace App\Services\Cache;

use Illuminate\Support\Facades\Storage;

/**
 * Persists a list of analyzed photo IDs with timestamps in a local JSON file.
 * No database required — state is stored in storage/app/<history_file>.
 *
 * Schema: { "<photo_id>": "<ISO 8601 timestamp>", ... }
 */
class AnalysisHistoryCache
{
    /** @var array<string, string> */
    private array $data = [];

    private bool $loaded = false;

    private string $filePath;

    public function __construct()
    {
        $this->filePath = config('amazon-photos.history_file', 'amazon-photos/analysis-history.json');
    }

    public function wasAnalyzedWithin(string $photoId, int $days): bool
    {
        $this->load();

        if (! isset($this->data[$photoId])) {
            return false;
        }

        $analyzedAt = \Carbon\Carbon::parse($this->data[$photoId]);

        return $analyzedAt->isAfter(\Carbon\Carbon::now()->subDays($days));
    }

    /**
     * Mark a set of photo IDs as analyzed right now.
     *
     * @param  array<int, string>  $photoIds
     */
    public function markAnalyzed(array $photoIds): void
    {
        $this->load();

        $now = \Carbon\Carbon::now()->toIso8601String();

        foreach ($photoIds as $id) {
            $this->data[$id] = $now;
        }

        $this->save();
    }

    /**
     * Remove history entries older than the given number of days to keep the
     * file from growing indefinitely.
     */
    public function prune(int $olderThanDays = 90): void
    {
        $this->load();

        $cutoff = \Carbon\Carbon::now()->subDays($olderThanDays);

        $this->data = array_filter(
            $this->data,
            fn (string $ts) => \Carbon\Carbon::parse($ts)->isAfter($cutoff)
        );

        $this->save();
    }

    public function count(): int
    {
        $this->load();

        return count($this->data);
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if (! Storage::exists($this->filePath)) {
            $this->data = [];

            return;
        }

        $json = Storage::get($this->filePath);
        $decoded = json_decode($json, true);

        $this->data = is_array($decoded) ? $decoded : [];
    }

    private function save(): void
    {
        Storage::put($this->filePath, json_encode($this->data, JSON_PRETTY_PRINT));
    }
}
