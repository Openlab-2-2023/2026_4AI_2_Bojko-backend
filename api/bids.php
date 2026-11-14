<?php
// Endpoint for placing a bid.

// POST /api/listings/5/bids
// Body: { "amount": 425.00 }
// The bidder is the logged-in user from the session – never a value from the body.
function createBid(string $listingId): never
{
    $bidderId = requireLogin();
    $body     = readJsonBody();
    $amount   = $body['amount'] ?? null;

    // The amount must be a number with at most 2 decimal places.
    if (!is_int($amount) && !is_float($amount)) {
        sendError('Zadajte sumu ponuky.', 422, ['amount' => 'Zadajte sumu ponuky.']);
    }
    if ($amount <= 0 || round($amount, 2) != $amount) {
        sendError('Neplatná suma.', 422, ['amount' => 'Suma musí byť kladné číslo s najviac 2 desatinnými miestami.']);
    }

    $result = placeBid((int) $listingId, $bidderId, (float) $amount);

    // Send back the updated listing, so Angular can show the new price and history.
    sendJson([
        'listing'  => findListingById((int) $listingId),
        'extended' => $result['extended'],
    ], 201);
}
