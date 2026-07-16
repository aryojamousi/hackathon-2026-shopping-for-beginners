<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * DBAL repository for the crawled Thomann "Online Expert" guides.
 *
 * Reads from the read-only SQLite "guides" connection (see config/packages/doctrine.yaml),
 * kept separate from the primary product connection so the two never interfere.
 */
class GuideRepository
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.guides_connection')]
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<int, array{id: int, slug: string, title: string, url: string, category: string, description: string, imageUrl: string}>
     */
    public function findAll(): array
    {
        return $this->hydrate(
            $this->baseQuery()
                ->orderBy('g.category', 'ASC')
                ->addOrderBy('g.title', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative(),
        );
    }

    /**
     * @return array<int, array{id: int, slug: string, title: string, url: string, category: string, description: string, imageUrl: string}>
     */
    public function findByCategory(string $category): array
    {
        return $this->hydrate(
            $this->baseQuery()
                ->where('g.category = :category')
                ->orderBy('g.title', 'ASC')
                ->setParameter('category', $category)
                ->executeQuery()
                ->fetchAllAssociative(),
        );
    }

    /**
     * Guides in a category that have crawled article text, including that text.
     * Kept separate from the listing queries so the large `text` blobs are only
     * loaded when actually needed (i.e. for the advisor's grounding context).
     *
     * @return array<int, array{title: string, url: string, text: string}>
     */
    public function findByCategoryWithText(string $category): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('g.title AS title', 'g.url AS url', 'g.text AS text')
            ->from('guides', 'g')
            ->where('g.category = :category')
            ->andWhere("g.text IS NOT NULL AND g.text <> ''")
            ->orderBy('g.title', 'ASC')
            ->setParameter('category', $category)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'title' => (string) $row['title'],
            'url' => (string) $row['url'],
            'text' => (string) ($row['text'] ?? ''),
        ], $rows);
    }

    /**
     * Guides grouped by their instrument/topic category.
     *
     * @return array<string, array<int, array{id: int, slug: string, title: string, url: string, category: string, description: string, imageUrl: string}>>
     */
    public function findAllGroupedByCategory(): array
    {
        $grouped = [];
        foreach ($this->findAll() as $guide) {
            $grouped[$guide['category']][] = $guide;
        }

        return $grouped;
    }

    /**
     * @return list<string>
     */
    public function findCategories(): array
    {
        return $this->connection->createQueryBuilder()
            ->select('DISTINCT category')
            ->from('guides')
            ->orderBy('category', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();
    }

    private function baseQuery(): \Doctrine\DBAL\Query\QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'g.id AS id',
                'g.slug AS slug',
                'g.title AS title',
                'g.url AS url',
                'g.category AS category',
                'g.description AS description',
                'g.image_url AS image_url',
            )
            ->from('guides', 'g');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array{id: int, slug: string, title: string, url: string, category: string, description: string, imageUrl: string}>
     */
    private function hydrate(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'url' => (string) $row['url'],
            'category' => (string) $row['category'],
            'description' => (string) ($row['description'] ?? ''),
            'imageUrl' => (string) ($row['image_url'] ?? ''),
        ], $rows);
    }
}
