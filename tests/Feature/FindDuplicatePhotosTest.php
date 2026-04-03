<?php

namespace Tests\Feature;

use App\DTOs\Photo;
use App\Services\AmazonPhotos\PhotoService;
use App\Services\ImageComparatorService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FindDuplicatePhotosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->withValidCredentials();
    }

    // -------------------------------------------------------------------------
    // Happy path: visual comparison confirms duplicates
    // -------------------------------------------------------------------------

    public function test_duplicate_pair_is_confirmed_when_similarity_meets_threshold(): void
    {
        $a = $this->makePhoto('photo-1', name: 'IMG_1234.jpg', url: 'https://example.com/1.jpg');
        $b = $this->makePhoto('photo-2', name: 'IMG_1234.jpg', url: 'https://example.com/2.jpg');
        $c = $this->makePhoto('photo-3', name: 'IMG_9999.jpg', url: 'https://example.com/3.jpg');

        $this->mockPhotoService(collect([$a, $b, $c]));
        $this->mockComparatorService([[$a, $b, 95.0]]);

        $this->artisan('photos:find-duplicates')
            ->assertSuccessful()
            ->expectsOutputToContain('1 duplicate pairs');
    }

    // -------------------------------------------------------------------------
    // Pair below threshold is excluded
    // -------------------------------------------------------------------------

    public function test_pair_below_threshold_is_not_reported(): void
    {
        $a = $this->makePhoto('photo-1', name: 'IMG_1234.jpg', url: 'https://example.com/1.jpg');
        $b = $this->makePhoto('photo-2', name: 'IMG_1234.jpg', url: 'https://example.com/2.jpg');

        $this->mockPhotoService(collect([$a, $b]));
        $this->mockComparatorService([[$a, $b, 60.0]]);

        $this->artisan('photos:find-duplicates')
            ->assertSuccessful()
            ->expectsOutputToContain('No visual duplicates confirmed');
    }

    // -------------------------------------------------------------------------
    // Custom similarity threshold
    // -------------------------------------------------------------------------

    public function test_custom_similarity_threshold_is_applied(): void
    {
        $a = $this->makePhoto('photo-1', name: 'IMG_1234.jpg', url: 'https://example.com/1.jpg');
        $b = $this->makePhoto('photo-2', name: 'IMG_1234.jpg', url: 'https://example.com/2.jpg');

        $this->mockPhotoService(collect([$a, $b]));
        $this->mockComparatorService([[$a, $b, 75.0]]);

        // 75% is below the default 90 but above our custom 70
        $this->artisan('photos:find-duplicates --similarity=70')
            ->assertSuccessful()
            ->expectsOutputToContain('1 duplicate pairs');
    }

    // -------------------------------------------------------------------------
    // Photos without URL are skipped
    // -------------------------------------------------------------------------

    public function test_photos_without_url_are_skipped(): void
    {
        $a = $this->makePhoto('photo-1', name: 'IMG_1234.jpg', url: null);
        $b = $this->makePhoto('photo-2', name: 'IMG_1234.jpg', url: null);

        $this->mockPhotoService(collect([$a, $b]));
        // Comparator returns null (skipped) — service is still called but returns null
        $this->mockComparatorService([[$a, $b, null]]);

        $this->artisan('photos:find-duplicates')
            ->assertSuccessful()
            ->expectsOutputToContain('No visual duplicates confirmed');
    }

    // -------------------------------------------------------------------------
    // No candidate pairs found (all names unique)
    // -------------------------------------------------------------------------

    public function test_reports_no_candidates_when_all_names_are_unique(): void
    {
        $this->mockPhotoService(collect([
            $this->makePhoto('photo-1', name: 'IMG_001.jpg'),
            $this->makePhoto('photo-2', name: 'IMG_002.jpg'),
        ]));

        $this->artisan('photos:find-duplicates')
            ->assertSuccessful()
            ->expectsOutputToContain('No candidate pairs found');
    }

    // -------------------------------------------------------------------------
    // group-by: taken-at
    // -------------------------------------------------------------------------

    public function test_candidates_grouped_by_taken_at(): void
    {
        $takenAt = Carbon::parse('2024-06-15 10:00:00');
        $a = $this->makePhoto('photo-1', name: 'IMG_001.jpg', takenAt: $takenAt, url: 'https://example.com/1.jpg');
        $b = $this->makePhoto('photo-2', name: 'IMG_002.jpg', takenAt: $takenAt, url: 'https://example.com/2.jpg');

        $this->mockPhotoService(collect([$a, $b]));
        $this->mockComparatorService([[$a, $b, 92.0]]);

        $this->artisan('photos:find-duplicates --group-by=taken-at')
            ->assertSuccessful()
            ->expectsOutputToContain('1 duplicate pairs');
    }

    // -------------------------------------------------------------------------
    // group-by: name-and-taken-at
    // -------------------------------------------------------------------------

    public function test_candidates_grouped_by_name_and_taken_at(): void
    {
        $takenAt = Carbon::parse('2024-06-15 10:00:00');
        $a = $this->makePhoto('photo-1', name: 'IMG_1234.jpg', takenAt: $takenAt, url: 'https://example.com/1.jpg');
        $b = $this->makePhoto('photo-2', name: 'IMG_1234.jpg', takenAt: $takenAt, url: 'https://example.com/2.jpg');
        // Same name but different takenAt — different candidate group, no comparison
        $c = $this->makePhoto('photo-3', name: 'IMG_1234.jpg', takenAt: Carbon::parse('2020-01-01'), url: 'https://example.com/3.jpg');

        $this->mockPhotoService(collect([$a, $b, $c]));
        $this->mockComparatorService([[$a, $b, 97.0]]);

        $this->artisan('photos:find-duplicates --group-by=name-and-taken-at')
            ->assertSuccessful()
            ->expectsOutputToContain('1 duplicate pairs');
    }

    // -------------------------------------------------------------------------
    // CSV export
    // -------------------------------------------------------------------------

    public function test_csv_is_exported_with_pair_and_similarity_columns(): void
    {
        $a = $this->makePhoto('photo-1', name: 'IMG_1234.jpg', url: 'https://example.com/1.jpg');
        $b = $this->makePhoto('photo-2', name: 'IMG_1234.jpg', url: 'https://example.com/2.jpg');

        $this->mockPhotoService(collect([$a, $b]));
        $this->mockComparatorService([[$a, $b, 95.0]]);

        $this->artisan('photos:find-duplicates --output=csv')
            ->assertSuccessful();

        Storage::assertExists('amazon-photos/duplicates.csv');

        $csv = Storage::get('amazon-photos/duplicates.csv');
        $this->assertStringContainsString('pair,similarity,id,name,uploaded_at,taken_at,url', $csv);
        $this->assertStringContainsString('photo-1', $csv);
        $this->assertStringContainsString('photo-2', $csv);
        $this->assertStringContainsString('95', $csv);
    }

    public function test_csv_custom_path_is_used(): void
    {
        $a = $this->makePhoto('photo-1', name: 'IMG_1234.jpg', url: 'https://example.com/1.jpg');
        $b = $this->makePhoto('photo-2', name: 'IMG_1234.jpg', url: 'https://example.com/2.jpg');

        $this->mockPhotoService(collect([$a, $b]));
        $this->mockComparatorService([[$a, $b, 95.0]]);

        $this->artisan('photos:find-duplicates --output=csv --csv-path=custom/dupes.csv')
            ->assertSuccessful();

        Storage::assertExists('custom/dupes.csv');
    }

    // -------------------------------------------------------------------------
    // Filters
    // -------------------------------------------------------------------------

    public function test_uploaded_last_days_filter_excludes_older_photos(): void
    {
        $a = $this->makePhoto('photo-1', name: 'IMG_1234.jpg', uploadedAt: Carbon::now()->subDays(3));
        $b = $this->makePhoto('photo-2', name: 'IMG_1234.jpg', uploadedAt: Carbon::now()->subDays(3));
        $old = $this->makePhoto('photo-3', name: 'IMG_1234.jpg', uploadedAt: Carbon::now()->subDays(60));

        $this->mockPhotoService(collect([$a, $b, $old]));
        $this->mockComparatorService([[$a, $b, 95.0]]);

        $this->artisan('photos:find-duplicates --uploaded-last-days=7')
            ->assertSuccessful()
            ->expectsOutputToContain('1 duplicate pairs');
    }

    public function test_uploaded_between_filter(): void
    {
        $a = $this->makePhoto('photo-1', name: 'IMG_1234.jpg', uploadedAt: Carbon::createFromFormat('d/m/Y', '10/01/2024'));
        $b = $this->makePhoto('photo-2', name: 'IMG_1234.jpg', uploadedAt: Carbon::createFromFormat('d/m/Y', '20/01/2024'));
        $out = $this->makePhoto('photo-3', name: 'IMG_1234.jpg', uploadedAt: Carbon::createFromFormat('d/m/Y', '01/03/2024'));

        $this->mockPhotoService(collect([$a, $b, $out]));
        $this->mockComparatorService([[$a, $b, 95.0]]);

        $this->artisan('photos:find-duplicates --uploaded-between=01/01/2024,31/01/2024')
            ->assertSuccessful()
            ->expectsOutputToContain('1 duplicate pairs');
    }

    public function test_taken_between_filter(): void
    {
        $a = $this->makePhoto('photo-1', name: 'IMG_1234.jpg', takenAt: Carbon::createFromFormat('d/m/Y', '10/06/2023'));
        $b = $this->makePhoto('photo-2', name: 'IMG_1234.jpg', takenAt: Carbon::createFromFormat('d/m/Y', '20/06/2023'));
        $out = $this->makePhoto('photo-3', name: 'IMG_1234.jpg', takenAt: Carbon::createFromFormat('d/m/Y', '10/01/2020'));

        $this->mockPhotoService(collect([$a, $b, $out]));
        $this->mockComparatorService([[$a, $b, 95.0]]);

        $this->artisan('photos:find-duplicates --taken-between=01/01/2023,31/12/2023')
            ->assertSuccessful()
            ->expectsOutputToContain('1 duplicate pairs');
    }

    // -------------------------------------------------------------------------
    // Direct comparison by ID
    // -------------------------------------------------------------------------

    public function test_compares_two_photos_by_id_when_both_arguments_are_given(): void
    {
        $a = $this->makePhoto('photo-1', name: 'IMG_001.jpg', url: 'https://example.com/1.jpg');
        $b = $this->makePhoto('photo-2', name: 'IMG_002.jpg', url: 'https://example.com/2.jpg');

        $mock = $this->createMock(PhotoService::class);
        $mock->method('fetchById')
            ->willReturnMap([['photo-1', $a], ['photo-2', $b]]);
        $this->app->instance(PhotoService::class, $mock);

        $this->mockComparatorService([[$a, $b, 92.0]]);

        $this->artisan('photos:find-duplicates photo-1 photo-2')
            ->assertSuccessful()
            ->expectsOutputToContain('92%')
            ->expectsOutputToContain('These photos are duplicates');
    }

    public function test_direct_comparison_reports_not_duplicate_when_below_threshold(): void
    {
        $a = $this->makePhoto('photo-1', name: 'IMG_001.jpg', url: 'https://example.com/1.jpg');
        $b = $this->makePhoto('photo-2', name: 'IMG_002.jpg', url: 'https://example.com/2.jpg');

        $mock = $this->createMock(PhotoService::class);
        $mock->method('fetchById')
            ->willReturnMap([['photo-1', $a], ['photo-2', $b]]);
        $this->app->instance(PhotoService::class, $mock);

        $this->mockComparatorService([[$a, $b, 50.0]]);

        $this->artisan('photos:find-duplicates photo-1 photo-2')
            ->assertSuccessful()
            ->expectsOutputToContain('NOT duplicates');
    }

    public function test_direct_comparison_fails_when_only_one_id_is_given(): void
    {
        $this->artisan('photos:find-duplicates photo-1')
            ->assertFailed()
            ->expectsOutputToContain('both photo IDs');
    }

    public function test_direct_comparison_fails_when_comparison_returns_null(): void
    {
        $a = $this->makePhoto('photo-1', name: 'IMG_001.jpg', url: null);
        $b = $this->makePhoto('photo-2', name: 'IMG_002.jpg', url: null);

        $mock = $this->createMock(PhotoService::class);
        $mock->method('fetchById')
            ->willReturnMap([['photo-1', $a], ['photo-2', $b]]);
        $this->app->instance(PhotoService::class, $mock);

        $this->mockComparatorService([[$a, $b, null]]);

        $this->artisan('photos:find-duplicates photo-1 photo-2')
            ->assertFailed()
            ->expectsOutputToContain('Could not compare');
    }

    // -------------------------------------------------------------------------
    // Validation errors
    // -------------------------------------------------------------------------

    public function test_fails_when_credentials_are_missing(): void
    {
        config(['amazon-photos.session_id' => '', 'amazon-photos.ubid' => '', 'amazon-photos.at' => '']);

        $this->artisan('photos:find-duplicates')
            ->assertFailed()
            ->expectsOutputToContain('credentials are not configured');
    }

    public function test_fails_with_invalid_group_by_option(): void
    {
        $this->mockPhotoService(collect());

        $this->artisan('photos:find-duplicates --group-by=invalid')
            ->assertFailed()
            ->expectsOutputToContain('Invalid --group-by');
    }

    public function test_fails_with_similarity_out_of_range(): void
    {
        $this->mockPhotoService(collect());

        $this->artisan('photos:find-duplicates --similarity=150')
            ->assertFailed()
            ->expectsOutputToContain('Invalid --similarity');
    }

    public function test_fails_with_invalid_date_format(): void
    {
        $this->artisan('photos:find-duplicates --uploaded-between=2024-01-01,2024-01-31')
            ->assertFailed()
            ->expectsOutputToContain('Invalid');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makePhoto(
        string $id,
        string $name = 'image.jpg',
        ?Carbon $uploadedAt = null,
        ?Carbon $takenAt = null,
        ?string $url = null,
    ): Photo {
        return new Photo(
            id: $id,
            name: $name,
            uploadedAt: $uploadedAt ?? Carbon::now(),
            takenAt: $takenAt,
            url: $url,
            parentIds: [],
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
     * Mock ImageComparatorService.
     * $expectations is an array of [Photo $a, Photo $b, float|null $returnValue].
     *
     * @param  array<int, array{0: Photo, 1: Photo, 2: float|null}>  $expectations
     */
    private function mockComparatorService(array $expectations): void
    {
        $mock = $this->createMock(ImageComparatorService::class);

        $mock->method('compare')->willReturnCallback(
            function (Photo $a, Photo $b) use ($expectations): ?float {
                foreach ($expectations as [$expA, $expB, $result]) {
                    if ($a->id === $expA->id && $b->id === $expB->id) {
                        return $result;
                    }
                }

                return null;
            }
        );

        $this->app->instance(ImageComparatorService::class, $mock);
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
