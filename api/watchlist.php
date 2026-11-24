<?php
// Endpoints for the watchlist ("Sledovať").

// POST /api/listings/5/watch
function watchListing(string $listingId): never
{
    $userId  = requireLogin();
    readJsonBody();   // only JSON requests are accepted (CSRF protection), the body itself is empty
    $listing = findListingById((int) $listingId);

    if ($listing === null) {
        sendError('Ponuka neexistuje.', 404);
    }
    if ($listing['seller']['id'] === $userId) {
        sendError('Vlastnú ponuku nemôžete sledovať.', 403);
    }

    // INSERT IGNORE: watching twice is not an error, the primary key just prevents a duplicate.
    db()->prepare('INSERT IGNORE INTO watchlist (user_id, listing_id) VALUES (:user, :listing)')
        ->execute(['user' => $userId, 'listing' => (int) $listingId]);

    sendJson(['watching' => true, 'watcherCount' => countWatchers((int) $listingId)]);
}

// DELETE /api/listings/5/watch
function unwatchListing(string $listingId): never
{
    $userId = requireLogin();

    db()->prepare('DELETE FROM watchlist WHERE user_id = :user AND listing_id = :listing')
        ->execute(['user' => $userId, 'listing' => (int) $listingId]);

    sendJson(['watching' => false, 'watcherCount' => countWatchers((int) $listingId)]);
}

function countWatchers(int $listingId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM watchlist WHERE listing_id = :id');
    $stmt->execute(['id' => $listingId]);
    return (int) $stmt->fetchColumn();
}
