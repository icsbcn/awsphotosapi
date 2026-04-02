<?php

namespace Tests\Feature;

use App\DTOs\Album;
use App\DTOs\Photo;
use App\Services\AmazonPhotos\AlbumService;
use App\Services\AmazonPhotos\PhotoService;
use App\Services\Cache\AnalysisHistoryCache;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FindUnclassifiedPhotosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->withValidCredentials();
    }

    // -------------------------------------------------------------------------
    // Happy path: unclassified photos are found and shown in console
    // -------------------------------------------------------------------------

    public function test_unclassified_photos_are_shown_in_console(): void
    {
        $photo1 = $this->makePhoto('photo-1');
        $photo2 = $this->makePhoto('photo-2');
        $albumedPhoto = $this->makePhoto('photo-3', parentIds: ['album-1']);

        $this->mockPhotoService(collect([$photo1, $photo2, $albumedPhoto]));
        $this->mockAlbumService(collect([new Album('album-1', 'Vacation')]));

        $this->artisan('photos:find-unclassified')
            ->assertSuccessful()
            ->expectsOutputToContain('Found')
            ->expectsOutputToContain('2');
    }

    // -------------------------------------------------------------------------
    // CSV export
    // -------------------------------------------------------------------------

    public function test_csv_is_exported_when_output_option_is_csv(): void
    {
        $photo1 = $this->makePhoto('photo-1');
        $photo2 = $this->makePhoto('photo-2');
        $albumedPhoto = $this->makePhoto('photo-3', parentIds: ['album-1']);

        $this->mockPhotoService(collect([$photo1, $photo2, $albumedPhoto]));
        $this->mockAlbumService(collect([new Album('album-1', 'Vacation')]));

        $this->artisan('photos:find-unclassified --output=csv')
            ->assertSuccessful();

        Storage::assertExists('amazon-photos/unclassified.csv');

        $csv = Storage::get('amazon-photos/unclassified.csv');
        $this->assertStringContainsString('id,name,uploaded_at,taken_at,url', $csv);
        $this->assertStringContainsString('photo-1', $csv);
        $this->assertStringContainsString('photo-2', $csv);
        $this->assertStringNotContainsString('photo-3', $csv);
    }

    // -------------------------------------------------------------------------
    // Filter: uploaded-last-days
    // -------------------------------------------------------------------------

    public function test_uploaded_last_days_filter_excludes_older_photos(): void
    {
        $recent = $this->makePhoto('photo-recent', uploadedAt: Carbon::now()->subDays(3));
        $old = $this->makePhoto('photo-old', uploadedAt: Carbon::now()->subDays(30));

        $this->mockPhotoService(collect([$recent, $old]));
        $this->mockAlbumService(collect());

        $this->artisan('photos:find-unclassified --uploaded-last-days=7')
            ->assertSuccessful()
            ->expectsOutputToContain('1');
    }

    // -------------------------------------------------------------------------
    // Filter: uploaded-between
    // -------------------------------------------------------------------------

    public function test_uploaded_between_filter(): void
    {
        $inRange = $this->makePhoto('photo-in', uploadedAt: Carbon::createFromFormat('d/m/Y', '15/01/2024'));
        $outOfRange = $this->makePhoto('photo-out', uploadedAt: Carbon::createFromFormat('d/m/Y', '01/03/2024'));

        $this->mockPhotoService(collect([$inRange, $outOfRange]));
        $this->mockAlbumService(collect());

        $this->artisan('photos:find-unclassified --uploaded-between=01/01/2024,31/01/2024')
            ->assertSuccessful()
            ->expectsOutputToContain('1');
    }

    // -------------------------------------------------------------------------
    // Filter: taken-between
    // -------------------------------------------------------------------------

    public function test_taken_between_filter(): void
    {
        $inRange = $this->makePhoto('photo-in', takenAt: Carbon::createFromFormat('d/m/Y', '10/06/2023'));
        $outOfRange = $this->makePhoto('photo-out', takenAt: Carbon::createFromFormat('d/m/Y', '10/01/2022'));
        $noExif = $this->makePhoto('photo-no-exif');

        $this->mockPhotoService(collect([$inRange, $outOfRange, $noExif]));
        $this->mockAlbumService(collect());

        $this->artisan('photos:find-unclassified --taken-between=01/01/2023,31/12/2023')
            ->assertSuccessful()
            ->expectsOutputToContain('1');
    }

    // -------------------------------------------------------------------------
    // Filter: skip-analyzed-days
    // -------------------------------------------------------------------------

    public function test_skip_analyzed_days_excludes_recently_analyzed_photos(): void
    {
        $photo = $this->makePhoto('photo-analyzed');

        // Pre-populate history
        $history = app(AnalysisHistoryCache::class);
        $history->markAnalyzed(['photo-analyzed']);

        $fresh = $this->makePhoto('photo-fresh');

        $this->mockPhotoService(collect([$photo, $fresh]));
        $this->mockAlbumService(collect());

        $this->artisan('photos:find-unclassified --skip-analyzed-days=7')
            ->assertSuccessful()
            ->expectsOutputToContain('1');
    }

    // -------------------------------------------------------------------------
    // Missing credentials
    // -------------------------------------------------------------------------

    public function test_fails_when_credentials_are_missing(): void
    {
        config(['amazon-photos.session_id' => '', 'amazon-photos.ubid' => '', 'amazon-photos.at' => '']);

        $this->artisan('photos:find-unclassified')
            ->assertFailed()
            ->expectsOutputToContain('credentials are not configured');
    }

    // -------------------------------------------------------------------------
    // Invalid date format
    // -------------------------------------------------------------------------

    public function test_fails_with_invalid_date_format(): void
    {
        $this->artisan('photos:find-unclassified --uploaded-between=2024-01-01,2024-01-31')
            ->assertFailed()
            ->expectsOutputToContain('Invalid');
    }

    // -------------------------------------------------------------------------
    // parentIds-based classification
    // -------------------------------------------------------------------------

    public function test_photo_with_album_parent_id_is_not_unclassified(): void
    {
        $photo = $this->makePhoto('photo-1', parentIds: ['root-folder', 'album-1']);

        $this->mockPhotoService(collect([$photo]));
        $this->mockAlbumService(collect([new Album('album-1', 'Fotos Alba')]));

        $this->artisan('photos:find-unclassified')
            ->assertSuccessful()
            ->expectsOutputToContain('All photos are classified');
    }

    // -------------------------------------------------------------------------
    // All photos classified — no results
    // -------------------------------------------------------------------------

    public function test_reports_nothing_when_all_photos_are_classified(): void
    {
        $photo1 = $this->makePhoto('photo-1', parentIds: ['album-1']);
        $photo2 = $this->makePhoto('photo-2', parentIds: ['album-1']);
        $photo3 = $this->makePhoto('photo-3', parentIds: ['album-1']);

        $this->mockPhotoService(collect([$photo1, $photo2, $photo3]));
        $this->mockAlbumService(collect([new Album('album-1', 'Vacation')]));

        $this->artisan('photos:find-unclassified')
            ->assertSuccessful()
            ->expectsOutputToContain('All photos are classified');
    }

    // -------------------------------------------------------------------------
    // History is persisted after run
    // -------------------------------------------------------------------------

    public function test_analysis_history_is_persisted_after_run(): void
    {
        $photo1 = $this->makePhoto('photo-1');
        $photo2 = $this->makePhoto('photo-2');

        $this->mockPhotoService(collect([$photo1, $photo2]));
        $this->mockAlbumService(collect());

        $this->artisan('photos:find-unclassified')->assertSuccessful();

        $history = app(AnalysisHistoryCache::class);
        $this->assertTrue($history->wasAnalyzedWithin($photo1->id, 1));
        $this->assertTrue($history->wasAnalyzedWithin($photo2->id, 1));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, string>  $parentIds
     */
    private function makePhoto(
        string $id,
        ?Carbon $uploadedAt = null,
        ?Carbon $takenAt = null,
        array $parentIds = [],
    ): Photo {
        return new Photo(
            id: $id,
            name: "image-{$id}.jpg",
            uploadedAt: $uploadedAt ?? Carbon::now(),
            takenAt: $takenAt,
            url: null,
            parentIds: $parentIds,
        );
    }

    /**
     * @param  Collection<int, Photo>  $photos
     */
    private function mockPhotoService(Collection $photos): void
    {
        $mock = $this->createMock(PhotoService::class);
        $mock->method('fetchAll')->willReturn($photos);
        $this->app->instance(PhotoService::class, $mock);
    }

    /**
     * @param  Collection<int, Album>  $albums
     */
    private function mockAlbumService(Collection $albums): void
    {
        $mock = $this->createMock(AlbumService::class);
        $mock->method('fetchAll')->willReturn($albums);
        $this->app->instance(AlbumService::class, $mock);
    }

    private function withValidCredentials(): void
    {
        config([
            'amazon-photos.session_id' => 'test-session-id',
            'amazon-photos.ubid' => 'test-ubid',
            'amazon-photos.at' => 'test-at',
            'amazon-photos.tld' => 'com',
        ]);
    }
}
