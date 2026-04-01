<?php

namespace App\Support;

use App\DTOs\Photo;
use App\Services\Cache\AnalysisHistoryCache;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PhotoFilter
{
    /**
     * @param  Collection<int, Photo>  $photos
     * @param  array<string, mixed>  $filters  Supported keys:
     *                                          - skip_analyzed_days  (int)  Skip photos analyzed within N days
     *                                          - uploaded_last_days  (int)  Only photos uploaded in last N days
     *                                          - uploaded_between    (array{from: Carbon, to: Carbon})
     *                                          - taken_between       (array{from: Carbon, to: Carbon})
     * @return Collection<int, Photo>
     */
    public static function apply(
        Collection $photos,
        array $filters,
        AnalysisHistoryCache $history,
    ): Collection {
        return $photos
            ->when(
                isset($filters['skip_analyzed_days']) && $filters['skip_analyzed_days'] > 0,
                fn (Collection $c) => $c->filter(
                    fn (Photo $p) => ! $history->wasAnalyzedWithin($p->id, (int) $filters['skip_analyzed_days'])
                )
            )
            ->when(
                isset($filters['uploaded_last_days']) && $filters['uploaded_last_days'] > 0,
                fn (Collection $c) => $c->filter(
                    fn (Photo $p) => $p->uploadedAt->isAfter(
                        Carbon::now()->subDays((int) $filters['uploaded_last_days'])
                    )
                )
            )
            ->when(
                isset($filters['uploaded_between']),
                fn (Collection $c) => $c->filter(
                    fn (Photo $p) => $p->uploadedAt->between(
                        $filters['uploaded_between']['from'],
                        $filters['uploaded_between']['to'],
                    )
                )
            )
            ->when(
                isset($filters['taken_between']),
                fn (Collection $c) => $c->filter(
                    fn (Photo $p) => $p->takenAt !== null && $p->takenAt->between(
                        $filters['taken_between']['from'],
                        $filters['taken_between']['to'],
                    )
                )
            )
            ->values();
    }
}
