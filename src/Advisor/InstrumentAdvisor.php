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
 * Retrieves the relevant slice of the Thomann catalogue (guides, Learn articles
 * and products) for a beginner's situation, hands it to an LLM as grounding
 * context, and returns friendly, cited advice. The model is instructed to
 * recommend only from the provided context so answers stay grounded in real
 * data rather than hallucinated products or prices.
 *
 * Backend: OpenRouter's OpenAI-compatible chat-completions API, called directly
 * over HTTP (no SDK, no local proxy). Swap models via OPENROUTER_MODEL.
 */
final class InstrumentAdvisor
{
    /**
     * Keywords (English + German) used to shortlist products per category.
     * Product names in the ncart catalogue are largely German.
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
     * Return grounded advice for the beginner's situation as a single string.
     *
     * @throws AdvisorUnavailableException when no API key is configured
     */
    public function adviseText(AdvisorRequest $request): string
    {
        if (!$this->isConfigured()) {
            throw new AdvisorUnavailableException('OPENROUTER_API_KEY is not set.');
        }

        $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/chat/completions', [
            'auth_bearer' => $this->apiKey,
            'headers' => [
                'Content-Type' => 'application/json',
                // Optional OpenRouter attribution headers.
                'X-Title' => 'Shopping for Beginners - Instrument Advisor',
            ],
            'json' => [
                'model' => $this->model,
                'max_tokens' => 2048,
                'messages' => [
                    ['role' => 'system', 'content' => $this->buildSystemPrompt()],
                    ['role' => 'user', 'content' => $this->buildUserMessage($request)],
                ],
            ],
            'timeout' => 60,
        ]);

        // toArray() throws on a non-2xx response; the controller catches it.
        $data = $response->toArray();
        $text = $data['choices'][0]['message']['content'] ?? '';

        return trim((string) $text);
    }

    private function buildSystemPrompt(): string
    {
        $persona = <<<'TXT'
            You are Thomann's friendly instrument-buying guide for absolute beginners.
            You help new musicians choose their first instrument and gear with warm,
            plain-language advice - never condescending, never jargon-heavy.

            Rules:
            - Recommend ONLY products, guides and articles from the context the user
              message provides. Never invent products, prices, brands or links.
            - When you reference a guide, article or product, include its link.
            - Respect the stated budget. If nothing in the shortlist fits, say so
              honestly and suggest what to prioritise instead.
            - Keep it concise and skimmable: a short reassuring intro, then concrete
              recommendations, then one or two "read next" links from the guides.
            - If the product shortlist is empty, still give solid advice from the
              guides and articles and explain what to look for when browsing.
            TXT;

        $lines = ['Available buying guides (Thomann Online Expert):'];
        foreach ($this->guides->findAll() as $guide) {
            $lines[] = sprintf('- [%s] %s: %s', $guide['category'], $guide['title'], $guide['url']);
        }

        return $persona."\n\n".implode("\n", $lines);
    }

    private function buildUserMessage(AdvisorRequest $request): string
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

        $parts[] = "\n".$this->relevantArticles($request->category);
        $parts[] = "\n".$this->productShortlist($request);
        $parts[] = "\nUsing only the guides, articles and products above, give this beginner your advice.";

        return implode("\n", $parts);
    }

    private function relevantArticles(string $category): string
    {
        $articles = $this->articles->findByCategory($category, 8);
        if ([] === $articles) {
            return 'Relevant Learn articles: (none for this category)';
        }

        $lines = ['Relevant Learn articles:'];
        foreach ($articles as $article) {
            $tags = $article['tags'] ? ' - tags: '.implode(', ', \array_slice($article['tags'], 0, 5)) : '';
            $lines[] = sprintf('- %s: %s%s', $article['title'], $article['url'], $tags);
        }

        return implode("\n", $lines);
    }

    private function productShortlist(AdvisorRequest $request): string
    {
        try {
            $keywords = self::PRODUCT_KEYWORDS[$request->category] ?? [];
            $products = $this->products->searchByKeywords($keywords, $request->budget, 6);
            if ([] === $products && [] !== $keywords) {
                $products = $this->products->searchByKeywords([], $request->budget, 6);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Advisor product lookup failed: {msg}', ['msg' => $e->getMessage()]);
            $products = [];
        }

        if ([] === $products) {
            return 'Product shortlist: (unavailable - advise from guides/articles and describe what to look for)';
        }

        $lines = ['Product shortlist (real Thomann catalogue):'];
        foreach ($products as $p) {
            $brand = $p['manufacturer'] ?: $p['brand'];
            $lines[] = sprintf(
                '- %s %s - %.2f EUR (https://www.thomann.de/intl/%s.htm)',
                $brand,
                $p['name'],
                $p['price'],
                $p['artnr'],
            );
        }

        return implode("\n", $lines);
    }
}
