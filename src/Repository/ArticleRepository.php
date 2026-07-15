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

        return array_map(static fn (array $row): array => [
            'artid' => (int) $row['artid'],
            'artnr' => (string) $row['artnr'],
            'brand' => (string) $row['brand'],
            'name' => (string) $row['name'],
            'price' => (float) $row['price'],
            'manufacturer' => $row['manufacturer'],
        ], $rows);
    }
}
