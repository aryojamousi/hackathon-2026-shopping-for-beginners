<?php

namespace App\Advisor;

/**
 * The beginner's situation, captured from the journey and passed to the advisor.
 */
final class AdvisorRequest
{
    public function __construct(
        public readonly string $category,
        public readonly ?int $budget = null,
        public readonly string $question = '',
        public readonly ?string $experience = null,
    ) {
    }
}
