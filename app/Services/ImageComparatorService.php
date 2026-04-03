<?php

namespace App\Services;

use App\DTOs\Photo;
use Illuminate\Support\Facades\Log;
use SapientPro\ImageComparator\ImageComparator;
use SapientPro\ImageComparator\ImageResourceException;

class ImageComparatorService
{
    public function __construct(private readonly ImageComparator $comparator) {}

    /**
     * Compare two photos visually using perceptual hashing.
     *
     * Returns a similarity percentage (0–100), or null if either photo has no
     * download URL or if the image could not be loaded (e.g. expired URL).
     */
    public function compare(Photo $a, Photo $b): ?float
    {
        if ($a->url === null || $b->url === null) {
            Log::debug('Skipping image comparison: one or both photos have no URL.', [
                'photo_a' => $a->id,
                'photo_b' => $b->id,
            ]);

            return null;
        }

        try {
            return $this->comparator->compare($a->url, $b->url);
        } catch (ImageResourceException $e) {
            Log::warning('Image comparison failed: could not load image resource.', [
                'photo_a' => $a->id,
                'photo_b' => $b->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
