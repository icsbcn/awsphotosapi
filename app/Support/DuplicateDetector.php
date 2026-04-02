<?php

namespace App\Support;

use App\DTOs\Photo;
use Illuminate\Support\Collection;

class DuplicateDetector
{
    public const GROUP_BY_NAME = 'name';

    public const GROUP_BY_TAKEN_AT = 'taken-at';

    public const GROUP_BY_NAME_AND_TAKEN_AT = 'name-and-taken-at';

    /**
     * Group photos into candidate sets based on lightweight metadata criteria.
     * Only groups with 2 or more photos are returned, as they are the candidates
     * worth comparing visually.
     *
     * When grouping by taken-at or name-and-taken-at, photos without a takenAt
     * value are excluded because they cannot form a meaningful candidate key.
     *
     * @param  Collection<int, Photo>  $photos
     * @param  string  $groupBy  One of: 'name', 'taken-at', 'name-and-taken-at'
     * @return Collection<int, Collection<int, Photo>>
     */
    public static function candidateGroups(Collection $photos, string $groupBy = self::GROUP_BY_NAME): Collection
    {
        $eligible = match ($groupBy) {
            self::GROUP_BY_TAKEN_AT, self::GROUP_BY_NAME_AND_TAKEN_AT => $photos->filter(
                fn (Photo $p) => $p->takenAt !== null
            ),
            default => $photos,
        };

        return $eligible
            ->groupBy(fn (Photo $p) => self::key($p, $groupBy))
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->values();
    }

    private static function key(Photo $photo, string $groupBy): string
    {
        return match ($groupBy) {
            self::GROUP_BY_TAKEN_AT => $photo->takenAt->toIso8601String(),
            self::GROUP_BY_NAME_AND_TAKEN_AT => $photo->name.'|'.$photo->takenAt->toIso8601String(),
            default => $photo->name,
        };
    }
}
