<?php
// Business rules for buying (simulated checkout – no real payment).
//
// One order per listing (orders.listing_id is UNIQUE). It is created when:
//   fixed price → the buyer clicks "Kúpiť ihneď"
//   auction     → the winner clicks "Zaplatiť" after the auction ended
// The payment is simulated, so the order is "paid" immediately.

/**
 * Creates the order and returns its id. Throws ApiException when a rule is broken.
 */
function createOrder(int $listingId, int $buyerId): int
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        // Lock the listing, so two buyers can't buy the same item at the same moment.
        $stmt = $pdo->prepare(
            'SELECT *, NOW() < start_time AS not_started, NOW() >= end_time AS has_ended
             FROM listings WHERE id = :id FOR UPDATE'
        );
        $stmt->execute(['id' => $listingId]);
        $listing = $stmt->fetch();

        if (!$listing) {
            throw new ApiException('Ponuka neexistuje.', 404);
        }
        if ((int) $listing['seller_id'] === $buyerId) {
            throw new ApiException('Nemôžete kúpiť vlastnú ponuku.', 403);
        }
        if (findOrderByListing($listingId) !== null) {
            throw new ApiException(
                $listing['listing_type'] === 'fixed' ? 'Tento tovar je už predaný.' : 'Táto aukcia je už zaplatená.',
                409
            );
        }

        if ($listing['listing_type'] === 'fixed') {
            if ($listing['not_started']) {
                throw new ApiException('Predaj sa ešte nezačal.', 409);
            }
        } else {
            // Auction: only the winner can pay, and only after the end.
            if (!$listing['has_ended']) {
                throw new ApiException('Aukcia ešte neskončila.', 409);
            }
            $winningBid = findHighestBid($listingId);
            if ($winningBid === null || (int) $winningBid['bidder_id'] !== $buyerId) {
                throw new ApiException('Zaplatiť môže iba víťaz aukcie.', 403);
            }
        }

        // The price comes from the database, never from the browser.
        $pdo->prepare(
            "INSERT INTO orders (listing_id, buyer_id, amount, status, paid_at)
             VALUES (:listing, :buyer, :amount, 'paid', NOW())"
        )->execute(['listing' => $listingId, 'buyer' => $buyerId, 'amount' => $listing['price']]);
        $orderId = (int) $pdo->lastInsertId();

        $pdo->commit();
        return $orderId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function findOrderByListing(int $listingId): ?array
{
    $stmt = db()->prepare('SELECT * FROM orders WHERE listing_id = :id');
    $stmt->execute(['id' => $listingId]);
    return $stmt->fetch() ?: null;
}

/** One order with the item and both people; null if it doesn't exist. */
function findOrderById(int $orderId): ?array
{
    $stmt = db()->prepare(
        'SELECT o.*, l.title, l.image_url, l.listing_type, l.seller_id,
                seller.username AS seller_username, buyer.username AS buyer_username
         FROM orders o
         JOIN listings l    ON l.id = o.listing_id
         JOIN users seller  ON seller.id = l.seller_id
         JOIN users buyer   ON buyer.id = o.buyer_id
         WHERE o.id = :id'
    );
    $stmt->execute(['id' => $orderId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }
    return [
        'id'        => (int) $row['id'],
        'status'    => $row['status'],
        'amount'    => (float) $row['amount'],
        'createdAt' => toIsoDate($row['created_at']),
        'paidAt'    => toIsoDate($row['paid_at']),
        'listing'   => [
            'id'       => (int) $row['listing_id'],
            'title'    => $row['title'],
            'imageUrl' => $row['image_url'],
            'type'     => $row['listing_type'],
        ],
        'buyer'     => ['id' => (int) $row['buyer_id'], 'username' => $row['buyer_username']],
        'seller'    => ['id' => (int) $row['seller_id'], 'username' => $row['seller_username']],
    ];
}
