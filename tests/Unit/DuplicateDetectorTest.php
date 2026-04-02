<?php

namespace Tests\Unit;

use App\DTOs\Photo;
use App\Support\DuplicateDetector;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class DuplicateDetectorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // GROUP_BY_NAME
    // -------------------------------------------------------------------------

    public function test_groups_photos_with_same_name(): void
    {
        $groups = DuplicateDetector::candidateGroups(collect([
            $this->photo('p1', 'IMG_1234.jpg'),
            $this->photo('p2', 'IMG_1234.jpg'),
            $this->photo('p3', 'IMG_9999.jpg'),
        ]));

        $this->assertCount(1, $groups);
        $this->assertCount(2, $groups->first());
    }

    public function test_returns_empty_when_no_name_duplicates(): void
    {
        $groups = DuplicateDetector::candidateGroups(collect([
            $this->photo('p1', 'IMG_001.jpg'),
            $this->photo('p2', 'IMG_002.jpg'),
        ]));

        $this->assertCount(0, $groups);
    }

    public function test_groups_multiple_name_candidate_sets(): void
    {
        $groups = DuplicateDetector::candidateGroups(collect([
            $this->photo('p1', 'IMG_AAA.jpg'),
            $this->photo('p2', 'IMG_AAA.jpg'),
            $this->photo('p3', 'IMG_BBB.jpg'),
            $this->photo('p4', 'IMG_BBB.jpg'),
            $this->photo('p5', 'IMG_CCC.jpg'),
        ]));

        $this->assertCount(2, $groups);
    }

    public function test_defaults_to_group_by_name(): void
    {
        $groups = DuplicateDetector::candidateGroups(collect([
            $this->photo('p1', 'IMG_1234.jpg'),
            $this->photo('p2', 'IMG_1234.jpg'),
        ]));

        $this->assertCount(1, $groups);
    }

    // -------------------------------------------------------------------------
    // GROUP_BY_TAKEN_AT
    // -------------------------------------------------------------------------

    public function test_groups_photos_with_same_taken_at(): void
    {
        $takenAt = Carbon::parse('2024-06-15 10:00:00');

        $groups = DuplicateDetector::candidateGroups(collect([
            $this->photo('p1', 'IMG_001.jpg', $takenAt),
            $this->photo('p2', 'IMG_002.jpg', $takenAt),
            $this->photo('p3', 'IMG_003.jpg', Carbon::parse('2024-06-15 11:00:00')),
        ]), DuplicateDetector::GROUP_BY_TAKEN_AT);

        $this->assertCount(1, $groups);
        $this->assertCount(2, $groups->first());
    }

    public function test_excludes_photos_without_taken_at_when_grouping_by_taken_at(): void
    {
        $groups = DuplicateDetector::candidateGroups(collect([
            $this->photo('p1', 'IMG_001.jpg', null),
            $this->photo('p2', 'IMG_002.jpg', null),
        ]), DuplicateDetector::GROUP_BY_TAKEN_AT);

        $this->assertCount(0, $groups);
    }

    // -------------------------------------------------------------------------
    // GROUP_BY_NAME_AND_TAKEN_AT
    // -------------------------------------------------------------------------

    public function test_groups_photos_with_same_name_and_taken_at(): void
    {
        $takenAt = Carbon::parse('2024-06-15 10:00:00');

        $groups = DuplicateDetector::candidateGroups(collect([
            $this->photo('p1', 'IMG_1234.jpg', $takenAt),
            $this->photo('p2', 'IMG_1234.jpg', $takenAt),
            // Same name but different takenAt — separate group, not returned (only 1 photo)
            $this->photo('p3', 'IMG_1234.jpg', Carbon::parse('2020-01-01 00:00:00')),
        ]), DuplicateDetector::GROUP_BY_NAME_AND_TAKEN_AT);

        $this->assertCount(1, $groups);
        $this->assertCount(2, $groups->first());
    }

    public function test_excludes_photos_without_taken_at_when_grouping_by_name_and_taken_at(): void
    {
        $groups = DuplicateDetector::candidateGroups(collect([
            $this->photo('p1', 'IMG_1234.jpg', null),
            $this->photo('p2', 'IMG_1234.jpg', null),
        ]), DuplicateDetector::GROUP_BY_NAME_AND_TAKEN_AT);

        $this->assertCount(0, $groups);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function photo(string $id, string $name, ?Carbon $takenAt = null): Photo
    {
        return new Photo(
            id: $id,
            name: $name,
            uploadedAt: Carbon::now(),
            takenAt: $takenAt,
            url: null,
            parentIds: [],
        );
    }
}
