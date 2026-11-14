<?php
// Business rules for bidding. All of them are checked here on the server –
// Angular only shows the result, so a modified browser can't break them.

const ANTI_SNIPING_SECONDS = 120;      // a bid in the last 2 minutes extends the auction
const MAX_BID_MULTIPLIER   = 10;       // a bid may be at most 10× the current price
const MAX_BID              = 1000000;  // and never more than 1 000 000 €

/**
 * Places a bid. Returns ['extended' => true] if the bid extended the auction.
 * Throws ApiException when a rule is broken.
 */
function placeBid(int $listingId, int $bidderId, float $amount): array
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        // 1) Lock the listing row until COMMIT (FOR UPDATE).
        //    If two people bid at the same moment, the second one waits here
        //    and then sees the first one's new price – so both can't "win" the same price.
        $stmt = $pdo->prepare(
            'SELECT *,
                    NOW() < start_time AS not_started,
                    NOW() >= end_time  AS has_ended,
                    TIMESTAMPDIFF(SECOND, NOW(), end_time) AS seconds_left
             FROM listings
             WHERE id = :id
             FOR UPDATE'
        );
        $stmt->execute(['id' => $listingId]);
        $listing = $stmt->fetch();

        // 2) Check the rules.
        if (!$listing) {
            throw new ApiException('Ponuka neexistuje.', 404);
        }
        if ($listing['listing_type'] !== 'auction') {
            throw new ApiException('Na túto ponuku sa nedá prihadzovať, dá sa len kúpiť.', 400);
        }
        if ((int) $listing['seller_id'] === $bidderId) {
            throw new ApiException('Na vlastnú aukciu nemôžete prihadzovať.', 403);
        }
        if ($listing['not_started']) {
            throw new ApiException('Aukcia sa ešte nezačala.', 409);
        }
        if ($listing['has_ended']) {
            throw new ApiException('Aukcia už skončila.', 409);
        }

        $highestBid = findHighestBid($listingId);
        if ($highestBid !== null && (int) $highestBid['bidder_id'] === $bidderId) {
            throw new ApiException('Vaša ponuka je už najvyššia.', 409);
        }

        // No bids yet → at least the starting price; otherwise current price + increment.
        $minimum = $highestBid === null
            ? (float) $listing['starting_price']
            : (float) $listing['price'] + (float) $listing['min_increment'];
        $maximum = min(MAX_BID, max($minimum, (float) $listing['price'] * MAX_BID_MULTIPLIER));

        // Money is compared in whole cents, so float rounding can't cause mistakes.
        if (toCents($amount) < toCents($minimum)) {
            throw new ApiException('Ponuka je príliš nízka.', 422, ['amount' => 'Minimálna ponuka je ' . formatEuro($minimum) . '.']);
        }
        if (toCents($amount) > toCents($maximum)) {
            throw new ApiException('Ponuka je príliš vysoká.', 422, ['amount' => 'Maximálna ponuka je ' . formatEuro($maximum) . '.']);
        }

        // 3) Save the bid and the new price.
        $pdo->prepare('INSERT INTO bids (listing_id, bidder_id, amount) VALUES (:listing, :bidder, :amount)')
            ->execute(['listing' => $listingId, 'bidder' => $bidderId, 'amount' => $amount]);

        // Anti-sniping: a bid in the last 2 minutes moves the end to "now + 2 minutes",
        // so the other bidders always have time to answer.
        $extended = (int) $listing['seconds_left'] < ANTI_SNIPING_SECONDS;
        $sql = $extended
            ? 'UPDATE listings SET price = :price, end_time = NOW() + INTERVAL ' . ANTI_SNIPING_SECONDS . ' SECOND WHERE id = :id'
            : 'UPDATE listings SET price = :price WHERE id = :id';
        $pdo->prepare($sql)->execute(['price' => $amount, 'id' => $listingId]);

        // 4) Everything succeeded → save it all at once.
        $pdo->commit();
        return ['extended' => $extended];
    } catch (Throwable $e) {
        // Something failed → undo everything from this transaction.
        $pdo->rollBack();
        throw $e;
    }
}

function findHighestBid(int $listingId): ?array
{
    $stmt = db()->prepare('SELECT bidder_id, amount FROM bids WHERE listing_id = :id ORDER BY amount DESC, id DESC LIMIT 1');
    $stmt->execute(['id' => $listingId]);
    return $stmt->fetch() ?: null;
}

function toCents(float $amount): int
{
    return (int) round($amount * 100);
}

// 1234.5 → "1 234,50 €"
function formatEuro(float $amount): string
{
    return number_format($amount, 2, ',', ' ') . ' €';
}
