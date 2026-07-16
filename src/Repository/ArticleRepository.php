<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Sample DBAL repository — reads directly from the "ncart" article table
 * (Thomann's internal NC schema) using the DBAL query builder, no ORM entities involved.
 */
class ArticleRepository
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<int, array{artid: int, artnr: string, brand: string, name: string, price: float, manufacturer: ?string}>
     */
    public function findFeatured(int $limit = 12): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select(
                'a.art_artid AS artid',
                'a.art_artnr AS artnr',
                'a.art_name1 AS brand',
                'a.art_name2 AS name',
                'a.art_verka AS price',
                'm.company_name AS manufacturer',
            )
            ->from('ncart', 'a')
            ->leftJoin('a', 'nc_manufacturer_info', 'm', 'm.id = a.art_manufacturer_info_id')
            ->where('a.art_vfsta = :active')
            ->andWhere('a.art_verka > 0')
            ->andWhere("a.art_name1 <> ''")
            ->orderBy('a.art_rankg', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('active', 1)
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->hydrate($rows);
    }

    /**
     * Search active products by keyword (matched against brand/name) and an
     * optional maximum price. Used by the instrument advisor to build a product
     * shortlist for a beginner's chosen category and budget.
     *
     * @param list<string> $keywords
     *
     * @return array<int, array{artid: int, artnr: string, brand: string, name: string, price: float, manufacturer: ?string}>
     */
    public function searchByKeywords(array $keywords, ?int $maxPrice = null, int $limit = 6): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select(
                'a.art_artid AS artid',
                'a.art_artnr AS artnr',
                'a.art_name1 AS brand',
                'a.art_name2 AS name',
                'a.art_verka AS price',
                'm.company_name AS manufacturer',
            )
            ->from('ncart', 'a')
            ->leftJoin('a', 'nc_manufacturer_info', 'm', 'm.id = a.art_manufacturer_info_id')
            ->where('a.art_vfsta = :active')
            ->andWhere('a.art_verka > 0')
            ->andWhere("a.art_name1 <> ''")
            ->orderBy('a.art_rankg', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('active', 1);

        $keywords = array_values(array_filter($keywords));
        if ([] !== $keywords) {
            $matches = [];
            foreach ($keywords as $i => $keyword) {
                $matches[] = $qb->expr()->like('a.art_name1', ":kw$i");
                $matches[] = $qb->expr()->like('a.art_name2', ":kw$i");
                $qb->setParameter("kw$i", '%'.$keyword.'%');
            }
            $qb->andWhere($qb->expr()->or(...$matches));
        }

        if (null !== $maxPrice) {
            $qb->andWhere('a.art_verka <= :maxPrice')
                ->setParameter('maxPrice', $maxPrice);
        }

        return $this->hydrate($qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array{artid: int, artnr: string, brand: string, name: string, price: float, manufacturer: ?string}>
     */
    private function hydrate(array $rows): array
    {
        // Scrub to valid UTF-8: the ncart table can carry stray bytes that
        // break json_encode (LLM requests) and Twig rendering.
        return array_map(static fn (array $row): array => [
            'artid' => (int) $row['artid'],
            'artnr' => (string) $row['artnr'],
            'brand' => mb_scrub((string) $row['brand']),
            'name' => mb_scrub((string) $row['name']),
            'price' => (float) $row['price'],
            'manufacturer' => null !== $row['manufacturer'] ? mb_scrub((string) $row['manufacturer']) : null,
        ], $rows);
    }
}
