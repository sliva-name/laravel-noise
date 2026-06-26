<?php

declare(strict_types=1);

namespace LaravelAudit\Pattern;

final class PatternReportMerger
{
    /**
     * @param  list<array<string, mixed>>  $existing
     * @param  list<PatternSuggestion>  $confirmed
     * @param  list<string>  $confirmedKeys
     * @return list<array<string, mixed>>
     */
    public static function merge(array $existing, array $confirmed, array $confirmedKeys): array
    {
        $keys = array_fill_keys($confirmedKeys, true);

        $remaining = array_values(array_filter(
            $existing,
            static function (array $item) use ($keys): bool {
                if (($item['source'] ?? 'heuristic') !== 'heuristic') {
                    return true;
                }

                return ! isset($keys[PatternHypothesisKey::fromArray($item)]);
            },
        ));

        return [
            ...$remaining,
            ...array_map(
                static fn (PatternSuggestion $suggestion): array => $suggestion->toArray(),
                $confirmed,
            ),
        ];
    }
}
