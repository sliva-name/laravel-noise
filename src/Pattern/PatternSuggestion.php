<?php

declare(strict_types=1);

namespace LaravelAudit\Pattern;

final readonly class PatternSuggestion
{
    /**
     * @param  array<string, float>  $features
     * @param  list<string>  $signals
     */
    public function __construct(
        public string $pattern,
        public string $title,
        public string $description,
        public string $recommendation,
        public float $confidence,
        public string $file,
        public int $line,
        public string $method,
        public string $class,
        public array $features,
        public array $signals = [],
        public ?string $llmRationale = null,
        public string $source = 'heuristic',
    ) {}

    public function hypothesisKey(): string
    {
        return PatternHypothesisKey::for($this);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'hypothesisKey' => $this->hypothesisKey(),
            'pattern' => $this->pattern,
            'title' => $this->title,
            'description' => $this->description,
            'recommendation' => $this->recommendation,
            'confidence' => round($this->confidence, 3),
            'location' => [
                'file' => $this->file,
                'line' => $this->line,
                'class' => $this->class,
                'method' => $this->method,
            ],
            'features' => $this->features,
            'signals' => $this->signals,
            'llmRationale' => $this->llmRationale,
            'source' => $this->source,
        ];
    }
}
