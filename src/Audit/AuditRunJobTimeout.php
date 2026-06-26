<?php

declare(strict_types=1);

namespace LaravelAudit\Audit;

final class AuditRunJobTimeout
{
    public static function forOptions(?AuditOptions $options): int
    {
        /** @var array<string, mixed> $config */
        $config = config('laravel-audit', []);

        $base = (int) data_get($config, 'dashboard.job_timeout', 180);

        if ($options === null || ! self::usesLlm($config, $options)) {
            return max(60, $base);
        }

        $llmTimeout = (int) data_get($config, 'patterns.llm.timeout', 120);

        if ($options->llmHypothesisKeys !== []) {
            return max(60, $base + ($llmTimeout * count($options->llmHypothesisKeys)));
        }

        $llmOverride = data_get($config, 'dashboard.llm_job_timeout');

        if (is_numeric($llmOverride) && (int) $llmOverride > 0) {
            return max(60, (int) $llmOverride);
        }

        $maxAttempts = self::maxLlmAttempts($config);

        return max(60, $base + ($llmTimeout * $maxAttempts));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function maxLlmAttempts(array $config): int
    {
        $configured = data_get($config, 'patterns.llm.max_attempts');

        if (is_numeric($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        $reviewLimit = (int) data_get(
            $config,
            'patterns.llm.review_limit',
            data_get($config, 'patterns.llm.refine_top', 3),
        );
        $patternLimit = (int) data_get($config, 'patterns.limit', 20);

        return max($reviewLimit * 3, min($patternLimit, 20));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function usesLlm(array $config, AuditOptions $options): bool
    {
        return $options->llm || (bool) data_get($config, 'patterns.llm.enabled', false);
    }
}
