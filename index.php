<?php
// Single entry point of the API. Every request to /api/... arrives here.
//
// Development:  php -S localhost:8000 backend/index.php
// Angular (ng serve) forwards /api requests here via proxy.conf.json.

date_default_timezone_set('Europe/Bratislava');

require __DIR__ . '/config/database.php';
require __DIR__ . '/config/session.php';
require __DIR__ . '/helpers/response.php';
require __DIR__ . '/middleware/auth.php';
require __DIR__ . '/services/listing_service.php';
require __DIR__ . '/services/category_service.php';
require __DIR__ . '/services/user_service.php';
require __DIR__ . '/services/bid_service.php';
require __DIR__ . '/api/listings.php';
require __DIR__ . '/api/categories.php';
require __DIR__ . '/api/auth.php';
require __DIR__ . '/api/bids.php';

// A broken business rule (ApiException) → its message and status code.
// Any other error (e.g. database down) → log the details, send a generic 500.
// The real error message is never shown to the browser.
set_exception_handler(function (Throwable $e) {
    if ($e instanceof ApiException) {
        sendError($e->getMessage(), $e->status, $e->fields);   // expected rule violation
    }
    error_log($e);
    sendError('Nastala chyba na serveri.', 500);
});

// Remember who is logged in (see config/session.php).
startSession();

// ---------------------------------------------------------------------
// Routes: [HTTP method, URL pattern, handler function]
// (\d+) captures a number from the URL and passes it to the handler.
// ---------------------------------------------------------------------
$routes = [
    ['GET',  '#^/api/health$#',          fn() => sendJson(['status' => 'ok'])],
    ['GET',  '#^/api/categories$#',      'listCategories'],
    ['GET',  '#^/api/listings$#',        'listListings'],
    ['GET',  '#^/api/listings/(\d+)$#',  'getListing'],
    ['POST', '#^/api/listings/(\d+)/bids$#', 'createBid'],

    ['POST', '#^/api/auth/register$#',   'register'],
    ['POST', '#^/api/auth/login$#',      'login'],
    ['POST', '#^/api/auth/logout$#',     'logout'],
    ['GET',  '#^/api/auth/me$#',         'me'],
];

$method = $_SERVER['REQUEST_METHOD'];
$path   = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

$pathExists = false;
foreach ($routes as [$routeMethod, $pattern, $handler]) {
    if (preg_match($pattern, $path, $matches)) {
        $pathExists = true;
        if ($routeMethod === $method) {
            $handler(...array_slice($matches, 1));   // e.g. getListing('5')
        }
    }
}

// The URL exists but not with this method (e.g. DELETE /api/listings) → 405
if ($pathExists) {
    sendError('Metóda nie je povolená.', 405);
}
sendError('Endpoint neexistuje.', 404);
