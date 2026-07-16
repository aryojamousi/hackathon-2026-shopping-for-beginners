<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * DBAL repository for the crawled Thomann blog "Learn" articles.
 *
 * Reads from the read-only SQLite "guides" connection (see config/packages/doctrine.yaml),
 * kept separate from the primary product connection so the two never interfere.
 *
 * Note: this is distinct from App\Repository\ArticleRepository, which reads
 * product data ("ncart") from the primary connection.
 */
class LearnArticleRepository
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.guides_connection')]
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<int, array{id: int, title: string, url: string, excerpt: string, category: string, tags: list<string>, imageUrl: string, publishedAt: string, readingTime: ?int}>
     */
    public function findLatest(int $limit = 24): array
    {
        return $this->hydrate(
            $this->baseQuery()
                ->orderBy('a.published_at', 'DESC')
                ->setMaxResults($limit)
                ->executeQuery()
                ->fetchAllAssociative(),
        );
    }

    /**
     * @return array<int, array{id: int, title: string, url: string, excerpt: string, category: string, tags: list<string>, imageUrl: string, publishedAt: string, readingTime: ?int}>
     */
    public function findByCategory(string $category, int $limit = 24): array
    {
        return $this->hydrate(
            $this->baseQuery()
                ->where('a.category = :category')
                ->orderBy('a.published_at', 'DESC')
                ->setMaxResults($limit)
                ->setParameter('category', $category)
                ->executeQuery()
                ->fetchAllAssociative(),
        );
    }

    /**
     * @return array<string, int> category => article count, most articles first
     */
    public function countByCategory(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('category', 'COUNT(*) AS total')
            ->from('articles')
            ->groupBy('category')
            ->orderBy('total', 'DESC')
            ->addOrderBy('category', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['category']] = (int) $row['total'];
        }

        return $counts;
    }

    private function baseQuery(): \Doctrine\DBAL\Query\QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'a.id AS id',
                'a.title AS title',
                'a.url AS url',
                'a.excerpt AS excerpt',
                'a.category AS category',
                'a.tags AS tags',
                'a.image_url AS image_url',
                'a.published_at AS published_at',
                'a.reading_time AS reading_time',
            )
            ->from('articles', 'a');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array{id: int, title: string, url: string, excerpt: string, category: string, tags: list<string>, imageUrl: string, publishedAt: string, readingTime: ?int}>
     */
    private function hydrate(array $rows): array
    {
        return array_map(static function (array $row): array {
            $tags = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) ($row['tags'] ?? '')),
            )));

            return [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'url' => (string) $row['url'],
                'excerpt' => (string) ($row['excerpt'] ?? ''),
                'category' => (string) $row['category'],
                'tags' => $tags,
                'imageUrl' => (string) ($row['image_url'] ?? ''),
                'publishedAt' => (string) ($row['published_at'] ?? ''),
                'readingTime' => isset($row['reading_time']) ? (int) $row['reading_time'] : null,
            ];
        }, $rows);
    }
}
