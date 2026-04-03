<?php

namespace App\DTOs;

final class DuplicatePair
{
    public function __construct(
        public readonly Photo $first,
        public readonly Photo $second,
        public readonly float $similarity,
    ) {}
}
