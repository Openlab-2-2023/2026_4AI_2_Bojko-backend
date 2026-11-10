<?php
// Endpoints for listings. They read and validate the request,
// call the listing service and send back JSON.

// GET /api/listings?q=iphone&category=elektronika&type=auction&status=active&sort=ending_soon&page=1&limit=24
function listListings(): never
{
    $q        = mb_substr(queryString('q'), 0, 100);
    $category = queryString('category');
    $type   = queryString('type');
    $status = queryString('status', 'active');
    $sort   = queryString('sort', 'newest');

    if (!in_array($type, ['', 'auction', 'fixed'], true)) {
        sendError('Neplatný typ ponuky.', 400);
    }
    if (!in_array($status, ['all', 'active', 'upcoming', 'ended', 'sold'], true)) {
        sendError('Neplatný stav ponuky.', 400);
    }
    if (!array_key_exists($sort, LISTING_SORTS)) {
        sendError('Neplatné zoradenie.', 400);
    }

    sendJson(findListings([
        'q'        => $q,
        'category' => $category,
        'type'     => $type,
        'status' => $status,
        'sort'   => $sort,
        'page'   => queryInt('page', 1, 1, 10000),
        'limit'  => queryInt('limit', 24, 1, 100),
    ]));
}

// GET /api/listings/5
function getListing(string $id): never
{
    $listing = findListingById((int) $id);

    if ($listing === null) {
        sendError('Ponuka neexistuje.', 404);
    }
    sendJson($listing);
}
