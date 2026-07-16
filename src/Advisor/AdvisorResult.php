<?php

namespace App\Advisor;

/**
 * Structured advisor answer: a Markdown summary plus the specific sources the
 * model selected (already validated against the retrieved candidate set).
 */
final class AdvisorResult
{
    /**
     * @param array<int, array{artid: int, artnr: string, fname: string, brand: string, name: string, price: float, manufacturer: ?string}> $products
     * @param array<int, array{title: string, url: string}> $guides
     * @param array<int, array{title: string, url: string}> $articles
     */
    public function __construct(
        public readonly string $summary,
        public readonly array $products = [],
        public readonly array $guides = [],
        public readonly array $articles = [],
    ) {
    }
}
