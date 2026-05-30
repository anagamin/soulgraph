<?php

namespace App\Domain\Shared;

use Illuminate\Support\Arr;

class EntityLabelNormalizer
{
    /** @var list<string> */
    private const PREFIXES = [
        'моя ', 'мой ', 'мои ', 'моё ', 'моему ', 'моей ', 'моего ', 'моих ',
        'г. ', 'г ', 'город ', 'городок ', 'пос. ', 'поселок ', 'село ', 'деревня ',
        'the ', 'my ',
    ];

    public static function normalize(string $label): string
    {
        $s = mb_strtolower(trim($label));
        $s = str_replace('ё', 'е', $s);
        $s = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = trim($s);

        $changed = true;
        while ($changed) {
            $changed = false;
            foreach (self::PREFIXES as $prefix) {
                if (str_starts_with($s, $prefix)) {
                    $s = trim(substr($s, strlen($prefix)));
                    $changed = true;
                }
            }
        }

        return $s;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function normalizedKey(string $type, string $label, array $attributes = []): string
    {
        $base = self::normalize($label);

        if (in_array($type, ['event', 'epoch'], true)) {
            $year = Arr::get($attributes, 'approx_year');
            if (is_string($year) && is_numeric($year)) {
                $year = (int) $year;
            }
            if (is_int($year)) {
                return "{$base}:{$year}";
            }

            $occurredAt = Arr::get($attributes, 'occurred_at');
            if (is_string($occurredAt) && $occurredAt !== '') {
                try {
                    $year = (int) date('Y', strtotime($occurredAt));

                    return "{$base}:{$year}";
                } catch (\Throwable) {
                    // fall through
                }
            }

            return "{$base}:unknown";
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function supportsKeyDedup(string $type, array $attributes = []): bool
    {
        $keyedTypes = config('ai.deduplication.keyed_types', []);

        if (! in_array($type, $keyedTypes, true)) {
            return false;
        }

        if (in_array($type, ['event', 'epoch'], true)) {
            $key = self::normalizedKey($type, '', $attributes);

            return ! str_ends_with($key, ':unknown');
        }

        return true;
    }

    public static function similarity(string $a, string $b): float
    {
        $na = self::normalize($a);
        $nb = self::normalize($b);

        if ($na === $nb) {
            return 1.0;
        }

        if ($na === '' || $nb === '') {
            return 0.0;
        }

        similar_text($na, $nb, $percent);

        return round($percent / 100, 4);
    }
}
