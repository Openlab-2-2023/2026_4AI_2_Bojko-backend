<?php
// Endpoints for orders (simulated checkout).

// POST /api/orders
// Body: { "listingId": 5 }
// Buys a fixed-price item, or pays for a won auction. The buyer is the
// logged-in user and the price comes from the database – neither is taken from the body.
function createOrderEndpoint(): never
{
    $buyerId   = requireLogin();
    $body      = readJsonBody();
    $listingId = $body['listingId'] ?? null;

    if (!is_int($listingId) || $listingId <= 0) {
        sendError('Chýba ponuka.', 422);
    }

    $orderId = createOrder($listingId, $buyerId);
    sendJson(findOrderById($orderId), 201);
}

// GET /api/orders/7
// Only the buyer and the seller may see an order.
function getOrder(string $id): never
{
    $userId = requireLogin();
    $order  = findOrderById((int) $id);

    if ($order === null) {
        sendError('Objednávka neexistuje.', 404);
    }
    if ($order['buyer']['id'] !== $userId && $order['seller']['id'] !== $userId) {
        sendError('K tejto objednávke nemáte prístup.', 403);
    }
    sendJson($order);
}
