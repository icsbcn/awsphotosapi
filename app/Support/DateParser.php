<?php

namespace App\Support;

use Carbon\Carbon;
use InvalidArgumentException;

class DateParser
{
    /**
     * Parse a date string in European format (dd/mm/yyyy) to a Carbon instance.
     */
    public static function parseEuropean(string $date): Carbon
    {
        $date = trim($date);

        if (! preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date)) {
            throw new InvalidArgumentException(
                "Invalid date format [{$date}]. Expected dd/mm/yyyy."
            );
        }

        return Carbon::createFromFormat('d/m/Y', $date)->startOfDay();
    }

    /**
     * Parse a "between" range string like "01/01/2024,31/01/2024".
     *
     * @return array{from: Carbon, to: Carbon}
     */
    public static function parseBetween(string $range): array
    {
        $parts = array_map('trim', explode(',', $range));

        if (count($parts) !== 2) {
            throw new InvalidArgumentException(
                "Invalid date range [{$range}]. Expected format: dd/mm/yyyy,dd/mm/yyyy"
            );
        }

        $from = self::parseEuropean($parts[0]);
        $to = self::parseEuropean($parts[1])->endOfDay();

        if ($from->isAfter($to)) {
            throw new InvalidArgumentException(
                "Start date [{$parts[0]}] must not be after end date [{$parts[1]}]."
            );
        }

        return ['from' => $from, 'to' => $to];
    }
}
