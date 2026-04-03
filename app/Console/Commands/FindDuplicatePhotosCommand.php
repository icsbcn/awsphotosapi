<?php

namespace App\Console\Commands;

use App\DTOs\DuplicatePair;
use App\DTOs\Photo;
use App\Services\AmazonPhotos\PhotoService;
use App\Services\Cache\AnalysisHistoryCache;
use App\Services\ImageComparatorService;
use App\Support\DateParser;
use App\Support\DuplicateDetector;
use App\Support\PhotoFilter;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FindDuplicatePhotosCommand extends Command
{
    protected $signature = 'photos:find-duplicates
        {photo1?                  : ID of the first photo to compare directly}
        {photo2?                  : ID of the second photo to compare directly}
        {--group-by=name          : Candidate criteria: "name", "taken-at", or "name-and-taken-at"}
        {--similarity=90          : Minimum similarity percentage to confirm a duplicate (0–100)}
        {--uploaded-last-days=0   : Only analyze photos uploaded in the last N days (0 = disabled)}
        {--uploaded-between=      : Only photos uploaded between two dates (dd/mm/yyyy,dd/mm/yyyy)}
        {--taken-between=         : Only photos taken between two dates (dd/mm/yyyy,dd/mm/yyyy)}
        {--output=console         : Output format: "console" or "csv"}
        {--csv-path=              : Path for the CSV file (defaults to storage/app/amazon-photos/duplicates.csv)}
    ';

    protected $description = 'Find Amazon Photos that are possible duplicates using visual comparison.';

    public function __construct(
        private readonly PhotoService $photoService,
        private readonly ImageComparatorService $comparatorService,
        private readonly AnalysisHistoryCache $history,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->credentialsConfigured()) {
            $this->error('Amazon Photos credentials are not configured. Please set AMAZON_PHOTOS_SESSION_ID, AMAZON_PHOTOS_UBID, and AMAZON_PHOTOS_AT in your .env file.');

            return self::FAILURE;
        }

        $photo1Id = $this->argument('photo1');
        $photo2Id = $this->argument('photo2');

        if ($photo1Id !== null || $photo2Id !== null) {
            if ($photo1Id === null || $photo2Id === null) {
                $this->error('You must provide both photo IDs to compare. Usage: photos:find-duplicates {photo1} {photo2}');

                return self::FAILURE;
            }

            return $this->compareTwoPhotosById($photo1Id, $photo2Id, (float) $this->option('similarity'));
        }

        $groupBy = $this->option('group-by');
        $validGroupBy = [DuplicateDetector::GROUP_BY_NAME, DuplicateDetector::GROUP_BY_TAKEN_AT, DuplicateDetector::GROUP_BY_NAME_AND_TAKEN_AT];
        if (! in_array($groupBy, $validGroupBy)) {
            $this->error("Invalid --group-by value: \"{$groupBy}\". Use \"name\", \"taken-at\", or \"name-and-taken-at\".");

            return self::FAILURE;
        }

        $threshold = (float) $this->option('similarity');
        if ($threshold < 0 || $threshold > 100) {
            $this->error('Invalid --similarity value. Must be between 0 and 100.');

            return self::FAILURE;
        }

        try {
            $filters = $this->parseFilters();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        Log::info('photos:find-duplicates started', ['group_by' => $groupBy, 'similarity' => $threshold, 'filters' => $filters]);

        // Step 1 — fetch all photos
        $this->info('Fetching all photos...');
        $allPhotos = $this->withProgress(
            'Fetching photos',
            fn () => $this->photoService->fetchAll()
        );

        // Step 2 — apply filters
        $filteredPhotos = PhotoFilter::apply($allPhotos, $filters, $this->history);
        $this->line("  → {$filteredPhotos->count()} photos after filters (of {$allPhotos->count()} total).");

        // Step 3 — group candidates by metadata
        $candidateGroups = DuplicateDetector::candidateGroups($filteredPhotos, $groupBy);
        $totalCandidatePairs = $candidateGroups->sum(fn (Collection $g) => intdiv($g->count() * ($g->count() - 1), 2));
        $this->line("  → {$totalCandidatePairs} candidate pairs across {$candidateGroups->count()} groups (grouped by \"{$groupBy}\").");

        if ($totalCandidatePairs === 0) {
            $this->newLine();
            $this->info('No candidate pairs found. No duplicates to report.');

            return self::SUCCESS;
        }

        // Step 4 — compare each candidate pair visually
        $this->newLine();
        $this->info('Comparing images… (downloads each candidate — may take a while)');
        $pairs = $this->compareCandidates($candidateGroups, $threshold);

        $this->newLine();
        $this->info("Found <comment>{$pairs->count()}</comment> duplicate pairs above {$threshold}% similarity.");

        if ($pairs->isEmpty()) {
            $this->line('No visual duplicates confirmed.');

            return self::SUCCESS;
        }

        $output = $this->option('output');

        if ($output === 'csv') {
            return $this->exportCsv($pairs);
        }

        $this->renderConsoleTable($pairs);

        return self::SUCCESS;
    }

    private function compareTwoPhotosById(string $id1, string $id2, float $threshold): int
    {
        $this->info("Fetching photo \"{$id1}\"...");
        $a = $this->photoService->fetchById($id1);

        $this->info("Fetching photo \"{$id2}\"...");
        $b = $this->photoService->fetchById($id2);

        $this->info('Comparing images…');
        $similarity = $this->comparatorService->compare($a, $b);

        if ($similarity === null) {
            $this->warn('Could not compare the two photos (missing URL or download failed).');

            return self::FAILURE;
        }

        $this->newLine();
        $this->renderConsoleTable(collect([new DuplicatePair($a, $b, $similarity)]));
        $this->line("Similarity: <comment>{$similarity}%</comment> (threshold: {$threshold}%)");

        if ($similarity >= $threshold) {
            $this->info('These photos are duplicates.');
        } else {
            $this->info('These photos are NOT duplicates.');
        }

        return self::SUCCESS;
    }

    /**
     * Compare all pairs within each candidate group and return confirmed duplicates.
     *
     * @param  Collection<int, Collection<int, Photo>>  $candidateGroups
     * @return Collection<int, DuplicatePair>
     */
    private function compareCandidates(Collection $candidateGroups, float $threshold): Collection
    {
        $totalPairs = $candidateGroups->sum(fn (Collection $g) => intdiv($g->count() * ($g->count() - 1), 2));
        $bar = $this->output->createProgressBar($totalPairs);
        $bar->setFormat(" %current%/%max% [%bar%] %percent:3s%% — %message%\n");
        $bar->setMessage('starting…');
        $bar->start();

        $confirmed = collect();

        $candidateGroups->each(function (Collection $group) use ($threshold, $bar, $confirmed) {
            $photos = $group->values();

            for ($i = 0; $i < $photos->count(); $i++) {
                for ($j = $i + 1; $j < $photos->count(); $j++) {
                    $a = $photos[$i];
                    $b = $photos[$j];

                    $bar->setMessage("\"{$a->name}\"");
                    $similarity = $this->comparatorService->compare($a, $b);
                    $bar->advance();

                    if ($similarity !== null && $similarity >= $threshold) {
                        $confirmed->push(new DuplicatePair($a, $b, $similarity));
                    }
                }
            }
        });

        $bar->finish();

        return $confirmed;
    }

    /**
     * @param  Collection<int, DuplicatePair>  $pairs
     */
    private function renderConsoleTable(Collection $pairs): void
    {
        $this->newLine();

        $pairs->each(function (DuplicatePair $pair, int $index) {
            $label = 'Pair '.($index + 1)." — {$pair->similarity}% similar";
            $this->line("<comment>{$label}</comment>");
            $this->table(
                ['ID', 'Name', 'Uploaded At', 'Taken At', 'URL'],
                collect([$pair->first, $pair->second])->map(fn (Photo $p) => [
                    $p->id,
                    mb_strimwidth($p->name, 0, 50, '…'),
                    $p->uploadedAt->format('d/m/Y H:i'),
                    $p->takenAt?->format('d/m/Y H:i') ?? '—',
                    $p->url ? mb_strimwidth($p->url, 0, 60, '…') : '—',
                ])->toArray()
            );
            $this->newLine();
        });
    }

    /**
     * @param  Collection<int, DuplicatePair>  $pairs
     */
    private function exportCsv(Collection $pairs): int
    {
        $path = $this->option('csv-path') ?: 'amazon-photos/duplicates.csv';

        $rows = [];
        $rows[] = implode(',', ['pair', 'similarity', 'id', 'name', 'uploaded_at', 'taken_at', 'url']);

        $pairs->each(function (DuplicatePair $pair, int $index) use (&$rows) {
            $pairNumber = (string) ($index + 1);
            $similarity = (string) $pair->similarity;

            foreach ([$pair->first, $pair->second] as $photo) {
                $row = array_merge(
                    ['pair' => $pairNumber, 'similarity' => $similarity],
                    $photo->toCsvRow()
                );
                $rows[] = implode(',', array_map(
                    fn (string $v) => '"'.str_replace('"', '""', $v).'"',
                    $row
                ));
            }
        });

        Storage::put($path, implode("\n", $rows));

        $absolutePath = Storage::path($path);
        $this->info("CSV exported to: {$absolutePath}");
        Log::info("CSV exported to: {$absolutePath}", ['pairs' => $pairs->count()]);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFilters(): array
    {
        $filters = [];

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
