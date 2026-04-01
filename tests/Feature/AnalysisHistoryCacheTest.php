<?php

namespace Tests\Feature;

use App\Services\Cache\AnalysisHistoryCache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnalysisHistoryCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
    }

    public function test_photo_not_in_history_is_not_considered_analyzed(): void
    {
        $cache = new AnalysisHistoryCache;

        $this->assertFalse($cache->wasAnalyzedWithin('photo-abc', 7));
    }

    public function test_recently_marked_photo_is_considered_analyzed(): void
    {
        $cache = new AnalysisHistoryCache;
        $cache->markAnalyzed(['photo-abc']);

        $this->assertTrue($cache->wasAnalyzedWithin('photo-abc', 7));
    }

    public function test_photo_is_not_analyzed_when_outside_window(): void
    {
        $cache = new AnalysisHistoryCache;

        // Manually write a stale timestamp
        $staleData = ['photo-abc' => now()->subDays(30)->toIso8601String()];
        Storage::put('amazon-photos/analysis-history.json', json_encode($staleData));

        // A fresh instance reads from disk
        $cache2 = new AnalysisHistoryCache;
        $this->assertFalse($cache2->wasAnalyzedWithin('photo-abc', 7));
    }

    public function test_multiple_photos_can_be_marked_at_once(): void
    {
        $cache = new AnalysisHistoryCache;
        $cache->markAnalyzed(['photo-1', 'photo-2', 'photo-3']);

        $this->assertTrue($cache->wasAnalyzedWithin('photo-1', 1));
        $this->assertTrue($cache->wasAnalyzedWithin('photo-2', 1));
        $this->assertTrue($cache->wasAnalyzedWithin('photo-3', 1));
    }

    public function test_prune_removes_old_entries(): void
    {
        $cache = new AnalysisHistoryCache;

        $data = [
            'photo-old' => now()->subDays(100)->toIso8601String(),
            'photo-recent' => now()->subDays(5)->toIso8601String(),
        ];
        Storage::put('amazon-photos/analysis-history.json', json_encode($data));

        $cache->prune(90);

        $cache2 = new AnalysisHistoryCache;
        $this->assertFalse($cache2->wasAnalyzedWithin('photo-old', 200));
        $this->assertTrue($cache2->wasAnalyzedWithin('photo-recent', 10));
    }

    public function test_count_returns_number_of_tracked_photos(): void
    {
        $cache = new AnalysisHistoryCache;
        $cache->markAnalyzed(['photo-1', 'photo-2']);

        $this->assertSame(2, $cache->count());
    }

    public function test_history_is_persisted_to_json_file(): void
    {
        $cache = new AnalysisHistoryCache;
        $cache->markAnalyzed(['photo-persisted']);

        Storage::assertExists('amazon-photos/analysis-history.json');

        $content = json_decode(Storage::get('amazon-photos/analysis-history.json'), true);
        $this->assertArrayHasKey('photo-persisted', $content);
    }
}
