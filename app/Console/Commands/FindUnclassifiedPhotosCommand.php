<?php

namespace App\Console\Commands;

use App\DTOs\Photo;
use App\Services\AmazonPhotos\AlbumService;
use App\Services\AmazonPhotos\PhotoService;
use App\Services\Cache\AnalysisHistoryCache;
use App\Support\DateParser;
use App\Support\PhotoFilter;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FindUnclassifiedPhotosCommand extends Command
{
    protected $signature = 'photos:find-unclassified
        {--skip-analyzed-days=0   : Skip photos already analyzed in the last N days (0 = disabled)}
        {--uploaded-last-days=0   : Only analyze photos uploaded in the last N days (0 = disabled)}
        {--uploaded-between=      : Only photos uploaded between two dates (dd/mm/yyyy,dd/mm/yyyy)}
        {--taken-between=         : Only photos taken between two dates (dd/mm/yyyy,dd/mm/yyyy)}
        {--output=console         : Output format: "console" or "csv"}
        {--csv-path=              : Path for the CSV file (defaults to storage/app/amazon-photos/unclassified.csv)}
    ';

    protected $description = 'Find Amazon Photos that are not classified in any album.';

    public function __construct(
        private readonly PhotoService $photoService,
        private readonly AlbumService $albumService,
        private readonly AnalysisHistoryCache $history,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Validate credentials
        if (! $this->credentialsConfigured()) {
            $this->error('Amazon Photos credentials are not configured. Please set AMAZON_PHOTOS_SESSION_ID, AMAZON_PHOTOS_UBID, and AMAZON_PHOTOS_AT in your .env file.');

            return self::FAILURE;
        }

        // Parse and validate filters
        try {
            $filters = $this->parseFilters();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        Log::info('photos:find-unclassified started', ['filters' => $filters]);

        // Step 1 — fetch all photos
        $this->info('Fetching all photos...');
        $allPhotos = $this->withProgress(
            'Fetching photos',
            fn () => $this->photoService->fetchAll()
        );

        // Step 2 — apply filters before doing expensive album fetching
        $filteredPhotos = PhotoFilter::apply($allPhotos, $filters, $this->history);
        $this->line("  → {$filteredPhotos->count()} photos after filters (of {$allPhotos->count()} total).");

        // Step 3 — fetch albums and their photo IDs
        $this->info('Fetching all albums...');
        $albums = $this->withProgress(
            'Fetching albums',
            fn () => $this->albumService->fetchAll()
        );
        $this->line("  → {$albums->count()} albums found.");

        $this->info('Fetching album contents...');
        $albumedIds = $this->withProgress(
            'Fetching album contents',
            fn () => $this->albumService->fetchAlbumedPhotoIds($albums)
        );
        $this->line("  → {$albumedIds->count()} unique photos belong to at least one album.");

        // Step 4 — find unclassified photos
        $unclassified = $filteredPhotos->filter(
            fn (Photo $p) => ! $albumedIds->contains($p->id)
        )->values();

        $this->newLine();
        $this->info("Found <comment>{$unclassified->count()}</comment> unclassified photos.");

        // Step 5 — mark analyzed
        $this->history->markAnalyzed($filteredPhotos->pluck('id')->toArray());
        Log::info("Marked {$filteredPhotos->count()} photos as analyzed.");

        // Step 6 — output results
        if ($unclassified->isEmpty()) {
            $this->line('All photos are classified. Nothing to report.');

            return self::SUCCESS;
        }

        $output = $this->option('output');

        if ($output === 'csv') {
            return $this->exportCsv($unclassified);
        }

        $this->renderConsoleTable($unclassified);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFilters(): array
    {
        $filters = [];

        $skipDays = (int) $this->option('skip-analyzed-days');
        if ($skipDays > 0) {
            $filters['skip_analyzed_days'] = $skipDays;
        }

        $uploadedLastDays = (int) $this->option('uploaded-last-days');
        if ($uploadedLastDays > 0) {
            $filters['uploaded_last_days'] = $uploadedLastDays;
        }

        $uploadedBetween = $this->option('uploaded-between');
        if (! empty($uploadedBetween)) {
            $filters['uploaded_between'] = DateParser::parseBetween($uploadedBetween);
        }

        $takenBetween = $this->option('taken-between');
        if (! empty($takenBetween)) {
            $filters['taken_between'] = DateParser::parseBetween($takenBetween);
        }

        return $filters;
    }

    /**
     * @param  Collection<int, Photo>  $photos
     */
    private function renderConsoleTable(Collection $photos): void
    {
        $this->newLine();
        $this->table(
            ['ID', 'Name', 'Uploaded At', 'Taken At', 'URL'],
            $photos->map(fn (Photo $p) => [
                $p->id,
                mb_strimwidth($p->name, 0, 50, '…'),
                $p->uploadedAt->format('d/m/Y H:i'),
                $p->takenAt?->format('d/m/Y H:i') ?? '—',
                $p->url ? mb_strimwidth($p->url, 0, 60, '…') : '—',
            ])->toArray()
        );
    }

    /**
     * @param  Collection<int, Photo>  $photos
     */
    private function exportCsv(Collection $photos): int
    {
        $path = $this->option('csv-path') ?: 'amazon-photos/unclassified.csv';

        $rows = [];
        $rows[] = implode(',', ['id', 'name', 'uploaded_at', 'taken_at', 'url']);

        foreach ($photos as $photo) {
            $row = $photo->toCsvRow();
            $rows[] = implode(',', array_map(
                fn (string $v) => '"'.str_replace('"', '""', $v).'"',
                $row
            ));
        }

        Storage::put($path, implode("\n", $rows));

        $absolutePath = Storage::path($path);
        $this->info("CSV exported to: {$absolutePath}");
        Log::info("CSV exported to: {$absolutePath}", ['count' => $photos->count()]);

        return self::SUCCESS;
    }

    /**
     * Run a callable and display a simple progress indicator.
     *
     * @template T
     *
     * @param  callable(): T  $callable
     * @return T
     */
    private function withProgress(string $label, callable $callable): mixed
    {
        $bar = $this->output->createProgressBar();
        $bar->setFormat(" %message% [%bar%] %elapsed:6s%\n");
        $bar->setMessage($label);
        $bar->start();

        $result = $callable();

        $bar->finish();

        return $result;
    }

    private function credentialsConfigured(): bool
    {
        return ! empty(config('amazon-photos.session_id'))
            && ! empty(config('amazon-photos.ubid'))
            && ! empty(config('amazon-photos.at'));
    }
}
