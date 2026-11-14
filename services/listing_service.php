<?php
// Database queries and business rules for listings (auctions + fixed price).
// The API files in /api call these functions; they never write SQL themselves.

// Base query used by both the list and the detail.
// It joins the category and seller, counts bids and watchers,
// and calculates the status (it is not stored in the database).
const LISTING_SELECT = "
    SELECT
        l.*,
        c.name     AS category_name,
        c.slug     AS category_slug,
        u.username AS seller_username,
        (SELECT COUNT(*) FROM bids b      WHERE b.listing_id = l.id) AS bid_count,
        (SELECT COUNT(*) FROM watchlist w WHERE w.listing_id = l.id) AS watcher_count,
        CASE
            WHEN l.listing_type = 'fixed' THEN IF(o.id IS NULL, 'active', 'sold')
            WHEN NOW() < l.start_time     THEN 'upcoming'
            WHEN NOW() < l.end_time       THEN 'active'
            ELSE 'ended'
        END AS status
    FROM listings l
    JOIN categories c ON c.id = l.category_id
    JOIN users u      ON u.id = l.seller_id
    LEFT JOIN orders o ON o.listing_id = l.id
";

// Allowed values of listings.item_condition (the same list as the ENUM in schema.sql).
// The API sends the key ("very_good"); Angular shows the Slovak label.
const ITEM_CONDITIONS = ['new', 'like_new', 'very_good', 'good', 'acceptable'];

// Allowed sort options. The user only chooses a key, never raw SQL,
// so ORDER BY cannot be used for SQL injection.
const LISTING_SORTS = [
    'newest'      => 'start_time DESC',
    'ending_soon' => 'end_time IS NULL, end_time ASC',   // fixed-price items (no end) go last
    'price_asc'   => 'price ASC',
    'price_desc'  => 'price DESC',
];

/**
 * Returns one page of listings plus the total count for pagination.
 * $filters: q (search text), category (slug), type ('auction'|'fixed'|''),
 *           status ('active'|'all'|...), sort, page, limit
 */
function findListings(array $filters): array
{
    $where  = [];
    $params = [];

    if ($filters['q'] !== '') {
        // Search in title and description. % and _ typed by the user are escaped,
        // so they are searched as normal characters, not used as LIKE wildcards.
        $search  = '%' . addcslashes($filters['q'], '%_\\') . '%';
        $where[] = '(title LIKE :q_title OR description LIKE :q_description)';
        $params['q_title']       = $search;
        $params['q_description'] = $search;
    }
    if ($filters['category'] !== '') {
        $where[] = 'category_slug = :category';
        $params['category'] = $filters['category'];
    }
    if ($filters['type'] !== '') {
        $where[] = 'listing_type = :type';
        $params['type'] = $filters['type'];
    }
    if ($filters['status'] !== 'all') {
        $where[] = 'status = :status';
        $params['status'] = $filters['status'];
    }

    // The base query is wrapped as a sub-query so we can filter on the calculated status.
    $from     = '(' . LISTING_SELECT . ') AS x';
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $orderSql = LISTING_SORTS[$filters['sort']] ?? LISTING_SORTS['newest'];

    // 1) total number of matching listings
    $countStmt = db()->prepare("SELECT COUNT(*) FROM $from $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // 2) the requested page
    $offset = ($filters['page'] - 1) * $filters['limit'];
    $stmt = db()->prepare("SELECT * FROM $from $whereSql ORDER BY $orderSql, id DESC LIMIT :limit OFFSET :offset");
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue('limit', $filters['limit'], PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'items'      => array_map('formatListing', $stmt->fetchAll()),
        'total'      => $total,
        'page'       => $filters['page'],
        'limit'      => $filters['limit'],
        'totalPages' => (int) ceil($total / $filters['limit']),
    ];
}

/**
 * Returns one listing with its gallery, bid history and seller rating,
 * or null if it does not exist.
 */
function findListingById(int $id): ?array
{
    $stmt = db()->prepare(LISTING_SELECT . ' WHERE l.id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $listing = formatListing($row);
    $listing['images'] = findListingImages($id, $row['image_url']);
    $listing['bids']   = $row['listing_type'] === 'auction' ? findBids($id) : [];
    $listing['seller'] += findSellerRating((int) $row['seller_id']);

    return $listing;
}

/** Gallery images; falls back to the cover image if there are no extra images. */
function findListingImages(int $listingId, ?string $coverImage): array
{
    $stmt = db()->prepare('SELECT image_url FROM listing_images WHERE listing_id = :id ORDER BY sort_order');
    $stmt->execute(['id' => $listingId]);
    $images = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$images && $coverImage) {
        $images = [$coverImage];
    }
    return $images;
}

/** Bid history, newest first. */
function findBids(int $listingId): array
{
    $stmt = db()->prepare(
        'SELECT b.id, b.amount, b.created_at, u.username
         FROM bids b
         JOIN users u ON u.id = b.bidder_id
         WHERE b.listing_id = :id
         ORDER BY b.amount DESC, b.created_at DESC'
    );
    $stmt->execute(['id' => $listingId]);

    return array_map(fn(array $row) => [
        'id'        => (int) $row['id'],
        'amount'    => (float) $row['amount'],
        'bidder'    => $row['username'],
        'createdAt' => toIsoDate($row['created_at']),
    ], $stmt->fetchAll());
}

/** Average rating and number of reviews a seller has received. */
function findSellerRating(int $sellerId): array
{
    $stmt = db()->prepare('SELECT AVG(rating) AS average, COUNT(*) AS count FROM reviews WHERE seller_id = :id');
    $stmt->execute(['id' => $sellerId]);
    $row = $stmt->fetch();

    return [
        'rating'      => $row['average'] === null ? null : round((float) $row['average'], 1),
        'reviewCount' => (int) $row['count'],
    ];
}

/**
 * The lowest amount the next bid must have.
 * No bids yet → the starting price. Otherwise → current price + minimum increment.
 * This rule lives only here in PHP; Angular just displays the result.
 */
function nextMinimumBid(array $row): ?float
{
    if ($row['listing_type'] !== 'auction') {
        return null;
    }
    if ((int) $row['bid_count'] === 0) {
        return (float) $row['starting_price'];
    }
    return round((float) $row['price'] + (float) $row['min_increment'], 2);
}

/** Converts a database row (snake_case, strings) to the JSON shape Angular uses. */
function formatListing(array $row): array
{
    return [
        'id'             => (int) $row['id'],
        'type'           => $row['listing_type'],
        'status'         => $row['status'],
        'title'          => $row['title'],
        'description'    => $row['description'],
        'condition'      => $row['item_condition'],
        'location'       => $row['location'],
        'imageUrl'       => $row['image_url'],
        'price'          => (float) $row['price'],
        'startingPrice'  => $row['starting_price'] === null ? null : (float) $row['starting_price'],
        'minIncrement'   => $row['min_increment'] === null ? null : (float) $row['min_increment'],
        'nextMinimumBid' => nextMinimumBid($row),
        'startTime'      => toIsoDate($row['start_time']),
        'endTime'        => toIsoDate($row['end_time']),
        'bidCount'       => (int) $row['bid_count'],
        'watcherCount'   => (int) $row['watcher_count'],
        'category'       => [
            'id'   => (int) $row['category_id'],
            'name' => $row['category_name'],
            'slug' => $row['category_slug'],
        ],
        'seller'         => [
            'id'       => (int) $row['seller_id'],
            'username' => $row['seller_username'],
        ],
    ];
}
