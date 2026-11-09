<?php
// Database queries for categories.

function findAllCategories(): array
{
    $sql = "SELECT c.id, c.name, c.slug, COUNT(l.id) AS listing_count
            FROM categories c
            LEFT JOIN listings l ON l.category_id = c.id
            GROUP BY c.id
            ORDER BY c.name";

    return array_map(fn(array $row) => [
        'id'           => (int) $row['id'],
        'name'         => $row['name'],
        'slug'         => $row['slug'],
        'listingCount' => (int) $row['listing_count'],
    ], db()->query($sql)->fetchAll());
}
