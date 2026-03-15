<?php

class Helpers
{
    /**
     * Convert date from DD.MM.YYYY to YYYY-MM-DD format.
     */
    public static function dateDot2Dash(string $date): string
    {
        $parts = explode('.', $date);
        return "{$parts[2]}-{$parts[1]}-{$parts[0]}";
    }

    /**
     * Smart rounding with threshold and precision support.
     *
     * @param float|null $value Value to round
     * @param bool $numberFormat Use number_format for output
     * @param int $maxPrecisionLevel Maximum decimal places
     * @param int $roundingPrecision Number of decimal digits
     * @param float $powerThreshold Minimum value (returns 0 below this)
     * @return float|string|null
     */
    public static function round($value, bool $numberFormat = false, int $maxPrecisionLevel = 9, int $roundingPrecision = 0, float $powerThreshold = 0)
    {
        if ($value === null) {
            return null;
        }
        if ($value < $powerThreshold) {
            return 0;
        }
        if ($numberFormat && $roundingPrecision) {
            return number_format($value, min($roundingPrecision, $maxPrecisionLevel), '.', '');
        } else {
            return round($value, $roundingPrecision);
        }
    }

    /**
     * Calculate power details (deltas and cumulative sums).
     *
     * @param array $powerDetailsWh Power details Wh array
     * @return array [deltas, cumulative]
     */
    public static function calculatePowerDetails(array $powerDetailsWh): array
    {
        $keyLast = false;
        $deltas = $powerDetailsWh;
        foreach ($powerDetailsWh as $key => $value) {
            if ($keyLast !== false) {
                $deltas[$keyLast] -= $value;
            }
            $keyLast = $key;
        }
        $cumSum = 0;
        $cumulative = [];
        foreach ($deltas as $key => $value) {
            $cumSum += $value;
            $cumulative[$key] = $cumSum;
        }
        return [$deltas, $cumulative];
    }
}

//EOF
