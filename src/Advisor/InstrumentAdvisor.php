<?php

namespace App\Advisor;

use App\Repository\ArticleRepository;
use App\Repository\GuideRepository;
use App\Repository\LearnArticleRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Retrieval-augmented instrument-buying advisor.
 *
 * Retrieves the relevant slice of the Thomann catalogue (guides + full guide
 * text, Learn articles, products) for a beginner's situation and asks the LLM
 * for a structured answer: a Markdown summary plus the specific sources it used.
 *
 * The source lists are constrained to the retrieved candidates via JSON-Schema
 * enums (the model can only pick real IDs/URLs), and validated again server-side.
 *
 * Backend: OpenRouter's OpenAI-compatible chat-completions API.
 */
final class InstrumentAdvisor
{
    /**
     * Keywords (English + German) used to shortlist products per category.
     *
     * @var array<string, list<string>>
     */
    private const PRODUCT_KEYWORDS = [
        'Guitars' => ['guitar', 'gitarre'],
        'Drums & Percussion' => ['drum', 'schlagzeug', 'cajon'],
        'Keys & Pianos' => ['piano', 'klavier', 'keyboard'],
        'Keys & Synths' => ['synth', 'keyboard'],
        'Recording & Studio' => ['interface', 'mikrofon', 'microphone'],
        'PA & Live Sound' => ['lautsprecher', 'speaker', 'monitor'],
        'Mixing' => ['mixer', 'mischpult'],
        'Cables & Accessories' => ['kabel', 'cable'],
        'Lighting & Stage' => ['light', 'licht', 'led'],
        'DJ' => ['dj', 'turntable', 'controller'],
        'Vocals' => ['mikrofon', 'microphone', 'vocal'],
        'Wind & Brass' => ['saxophon', 'trompete', 'trumpet', 'flute'],
        'Orchestral Strings' => ['violin', 'geige', 'cello'],
    ];

    private const MAX_GUIDE_TEXTS = 4;
    private const GUIDE_TEXT_CHARS = 2500;

    public function __construct(
        private readonly GuideRepository $guides,
        private readonly LearnArticleRepository $articles,
        private readonly ArticleRepository $products,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(OPENROUTER_API_KEY)%')]
        private readonly string $apiKey = '',
        #[Autowire('%env(OPENROUTER_MODEL)%')]
        private readonly string $model = 'openrouter/auto',
        #[Autowire('%env(OPENROUTER_BASE_URL)%')]
        private readonly string $baseUrl = 'https://openrouter.ai/api/v1',
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->apiKey);
    }

    /**
     * @throws AdvisorUnavailableException when no API key is configured
     */
    public function advise(AdvisorRequest $request): AdvisorResult
    {
        if (!$this->isConfigured()) {
            throw new AdvisorUnavailableException('OPENROUTER_API_KEY is not set.');
        }

        // --- Retrieve the candidate set (the only sources the model may cite) ---
        $guideCatalogue = $this->guides->findAll();
        $guideTexts = $this->guides->findByCategoryWithText($request->category);
        $articles = $this->articles->findByCategory($request->category, 8);
        $products = $this->fetchProducts($request);

        $productIds = array_values(array_map(static fn (array $p): string => $p['artnr'], $products));
        $guideUrls = array_values(array_map(static fn (array $g): string => $g['url'], $guideCatalogue));
        $blogUrls = array_values(array_map(static fn (array $a): string => $a['url'], $articles));

        // --- Ask for a structured answer constrained to those candidates ---
        $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/chat/completions', [
            'auth_bearer' => $this->apiKey,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Title' => 'Shopping for Beginners - Instrument Advisor',
            ],
            'json' => [
                'model' => $this->model,
                'max_tokens' => 2048,
                'messages' => [
                    // Scrub to valid UTF-8: catalogue data (esp. the external MySQL
                    // product table) can carry stray bytes that break json_encode.
                    ['role' => 'system', 'content' => mb_scrub($this->buildSystemPrompt($guideCatalogue))],
                    ['role' => 'user', 'content' => mb_scrub($this->buildUserMessage($request, $guideTexts, $articles, $products))],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'instrument_advice',
                        'strict' => true,
                        'schema' => $this->jsonSchema($productIds, $guideUrls, $blogUrls),
                    ],
                ],
                // Only route to providers/models that actually honour response_format.
                'provider' => ['require_parameters' => true],
            ],
            'timeout' => 60,
        ]);

        $data = $response->toArray();
        $content = (string) ($data['choices'][0]['message']['content'] ?? '');
        $parsed = json_decode($content, true);

        if (!\is_array($parsed)) {
            // Model didn't return JSON (e.g. a backend that ignored the schema).
            $this->logger->warning('Advisor structured output was not valid JSON; using raw content as summary.');

            return new AdvisorResult(summary: trim($content));
        }

        // --- Validate the model's selections against the candidate set ---
        return new AdvisorResult(
            summary: trim((string) ($parsed['summary'] ?? '')),
            products: $this->pick($products, 'artnr', $parsed['product_ids'] ?? []),
            guides: $this->pickColumns($guideCatalogue, 'url', $parsed['guide_urls'] ?? []),
            articles: $this->pickColumns($articles, 'url', $parsed['blog_urls'] ?? []),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     *
     * @return array<int, array<string, mixed>>
     */
    private function pick(array $candidates, string $key, mixed $selected): array
    {
        $chosen = \is_array($selected) ? array_flip(array_map('strval', $selected)) : [];

        return array_values(array_filter(
            $candidates,
            static fn (array $c): bool => isset($chosen[(string) $c[$key]]),
        ));
    }

    /**
     * Like pick(), but returns only {title, url} for rendering.
     *
     * @param array<int, array<string, mixed>> $candidates
     *
     * @return array<int, array{title: string, url: string}>
     */
    private function pickColumns(array $candidates, string $key, mixed $selected): array
    {
        return array_map(
            static fn (array $c): array => ['title' => (string) $c['title'], 'url' => (string) $c['url']],
            $this->pick($candidates, $key, $selected),
        );
    }

    /**
     * @return array<int, array{artid: int, artnr: string, brand: string, name: string, price: float, manufacturer: ?string}>
     */
    private function fetchProducts(AdvisorRequest $request): array
    {
        try {
            $keywords = self::PRODUCT_KEYWORDS[$request->category] ?? [];
            $products = $this->products->searchByKeywords($keywords, $request->budget, 6);
            if ([] === $products && [] !== $keywords) {
                $products = $this->products->searchByKeywords([], $request->budget, 6);
            }

            return $products;
        } catch (\Throwable $e) {
            $this->logger->warning('Advisor product lookup failed: {msg}', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param list<string> $productIds
     * @param list<string> $guideUrls
     * @param list<string> $blogUrls
     *
     * @return array<string, mixed>
     */
    private function jsonSchema(array $productIds, array $guideUrls, array $blogUrls): array
    {
        $enumArray = static function (array $enum, string $description): array {
            $items = ['type' => 'string'];
            if ([] !== $enum) {
                $items['enum'] = array_values(array_unique($enum));
            }

            return ['type' => 'array', 'items' => $items, 'description' => $description];
        };

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'product_ids', 'guide_urls', 'blog_urls'],
            'properties' => [
                'summary' => [
                    'type' => 'string',
                    'description' => 'Friendly, beginner-focused advice, formatted as Markdown.',
                ],
                'product_ids' => $enumArray($productIds, 'Article numbers of products you recommend. Choose only from the provided shortlist; [] if none apply.'),
                'guide_urls' => $enumArray($guideUrls, 'URLs of buying guides you reference. Choose only from the provided list; [] if none.'),
                'blog_urls' => $enumArray($blogUrls, 'URLs of Learn articles you reference. Choose only from the provided list; [] if none.'),
            ],
        ];
    }

    /**
     * @param array<int, array{id: int, slug: string, title: string, url: string, category: string, description: string, imageUrl: string}> $guideCatalogue
     */
    private function buildSystemPrompt(array $guideCatalogue): string
    {
        $persona = <<<'TXT'
            You are Thomann's friendly instrument-buying guide for absolute beginners.
            You help new musicians choose their first instrument and gear with warm,
            plain-language advice - never condescending, never jargon-heavy.

            You return a structured answer:
            - `summary`: the advice itself, as Markdown. A short reassuring intro, then
              concrete recommendations, then a "read next" nudge.
            - `product_ids`, `guide_urls`, `blog_urls`: the specific sources you actually
              used or recommend, chosen ONLY from the lists in the user message.

            Rules:
            - Recommend ONLY products, guides and articles that appear in the provided
              lists. Never invent products, prices, brands, IDs or links.
            - Respect the stated budget. If nothing fits, say so and explain what to
              prioritise instead (and leave product_ids empty).
            - Every source you name in the summary must also appear in the matching
              array, and vice versa.
            TXT;

        $lines = ['Full guide catalogue (you may cite any of these by URL):'];
        foreach ($guideCatalogue as $guide) {
            $lines[] = sprintf('- [%s] %s: %s', $guide['category'], $guide['title'], $guide['url']);
        }

        return $persona."\n\n".implode("\n", $lines);
    }

    /**
     * @param array<int, array{title: string, url: string, text: string}> $guideTexts
     * @param array<int, array{title: string, url: string, tags: list<string>, excerpt: string, ...}> $articles
     * @param array<int, array{artnr: string, brand: string, name: string, price: float, manufacturer: ?string, ...}> $products
     */
    private function buildUserMessage(AdvisorRequest $request, array $guideTexts, array $articles, array $products): string
    {
        $parts = [];

        $parts[] = "The beginner's situation:";
        $parts[] = '- Interested in: '.$request->category;
        if (null !== $request->budget) {
            $parts[] = '- Budget: up to '.$request->budget.' EUR';
        }
        if (null !== $request->experience && '' !== $request->experience) {
            $parts[] = '- Experience: '.$request->experience;
        }
        $parts[] = '- Their question: '.($request->question ?: 'What should I buy to get started?');

        if ([] !== $guideTexts) {
            $blocks = [];
            foreach (\array_slice($guideTexts, 0, self::MAX_GUIDE_TEXTS) as $guide) {
                $text = $guide['text'];
                if (mb_strlen($text) > self::GUIDE_TEXT_CHARS) {
                    $text = mb_substr($text, 0, self::GUIDE_TEXT_CHARS).'…';
                }
                $blocks[] = sprintf("### %s (%s)\n%s", $guide['title'], $guide['url'], $text);
            }
            $parts[] = "\nGuide reference material — cite by URL:\n".implode("\n\n", $blocks);
        }

        if ([] !== $articles) {
            $lines = ['Learn articles — cite by URL:'];
            foreach ($articles as $article) {
                $tags = $article['tags'] ? ' — tags: '.implode(', ', \array_slice($article['tags'], 0, 5)) : '';
                $lines[] = sprintf('- %s | %s%s', $article['url'], $article['title'], $tags);
            }
            $parts[] = "\n".implode("\n", $lines);
        }

        if ([] !== $products) {
            $lines = ['Product shortlist — cite by article number:'];
            foreach ($products as $p) {
                $brand = $p['manufacturer'] ?: $p['brand'];
                $lines[] = sprintf('- %s | %s %s | %.2f EUR', $p['artnr'], $brand, $p['name'], $p['price']);
            }
            $parts[] = "\n".implode("\n", $lines);
        } else {
            $parts[] = "\nProduct shortlist: (none available — advise from guides/articles and leave product_ids empty)";
        }

        $parts[] = "\nWrite `summary` as friendly Markdown advice. In product_ids, guide_urls and "
            .'blog_urls include ONLY identifiers from the lists above that you actually recommend '
            .'or reference. Use [] for any list where nothing applies.';

        return implode("\n", $parts);
    }
}
