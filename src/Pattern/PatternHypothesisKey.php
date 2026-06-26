<?php

declare(strict_types=1);

namespace LaravelAudit\Pattern;

final class PatternHypothesisKey
{
    public static function for(PatternSuggestion $suggestion): string
    {
        return $suggestion->pattern.':'.$suggestion->file.'::'.$suggestion->method;
    }

    /**
     * @param  array<string, mixed>  $suggestion
     */
    public static function fromArray(array $suggestion): string
    {
        if (is_string($suggestion['hypothesisKey'] ?? null) && $suggestion['hypothesisKey'] !== '') {
            return $suggestion['hypothesisKey'];
        }

        $location = is_array($suggestion['location'] ?? null) ? $suggestion['location'] : [];

        return ((string) ($suggestion['pattern'] ?? '')).':'
            .((string) ($location['file'] ?? '')).'::'
            .((string) ($location['method'] ?? ''));
    }

    public static function matches(PatternSuggestion $suggestion, string $key): bool
    {
        return self::for($suggestion) === $key;
    }
}
