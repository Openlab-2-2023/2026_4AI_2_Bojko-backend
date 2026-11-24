<?php
// Endpoints for listings. They read and validate the request,
// call the listing service and send back JSON.

// GET /api/listings?q=iphone&category=elektronika&type=auction&status=active
//                  &minPrice=100&maxPrice=500&condition=new,like_new&sort=ending_soon&page=1&limit=24
function listListings(): never
{
    $q        = mb_substr(queryString('q'), 0, 100);
    $category = queryString('category');
    $type     = queryString('type');
    $status   = queryString('status', 'active');
    $sort     = queryString('sort', 'newest');
    $minPrice = queryPrice('minPrice');
    $maxPrice = queryPrice('maxPrice');

    // ?condition=new,like_new → ['new', 'like_new']
    $conditionText = queryString('condition');
    $conditions = $conditionText === '' ? [] : array_unique(explode(',', $conditionText));

    if (!in_array($type, ['', 'auction', 'fixed'], true)) {
        sendError('Neplatný typ ponuky.', 400);
    }
    if (!in_array($status, ['all', 'active', 'upcoming', 'ended', 'sold'], true)) {
        sendError('Neplatný stav ponuky.', 400);
    }
    if (!array_key_exists($sort, LISTING_SORTS)) {
        sendError('Neplatné zoradenie.', 400);
    }
    if (array_diff($conditions, ITEM_CONDITIONS)) {
        sendError('Neplatný stav tovaru.', 400);
    }
    if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
        sendError('Cena „od“ nemôže byť vyššia ako cena „do“.', 400);
    }

    sendJson(findListings([
        'q'          => $q,
        'category'   => $category,
        'type'       => $type,
        'status'     => $status,
        'minPrice'   => $minPrice,
        'maxPrice'   => $maxPrice,
        'conditions' => $conditions,
        'sort'       => $sort,
        'page'       => queryInt('page', 1, 1, 10000),
        'limit'      => queryInt('limit', 24, 1, 100),
    ]));
}

// Reads an optional price from the query string: missing/empty → null, invalid → 400.
function queryPrice(string $name): ?float
{
    $value = queryString($name);
    if ($value === '') {
        return null;
    }
    if (!is_numeric($value) || (float) $value < 0) {
        sendError('Neplatná cena.', 400);
    }
    return (float) $value;
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
