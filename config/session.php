<?php
// Starts the PHP session that remembers who is logged in.
// The browser only gets a random session ID in a cookie; the user's id
// is stored on the server in $_SESSION and can't be changed by the browser.

function startSession(): void
{
    session_name('SELLIO_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,          // cookie is deleted when the browser closes
        'path'     => '/',
        'httponly' => true,       // JavaScript can't read the cookie (protects against XSS theft)
        'samesite' => 'Lax',      // other websites can't send requests with it (protects against CSRF)
        'secure'   => false,      // set to true when the site runs on HTTPS
    ]);
    ini_set('session.use_strict_mode', '1');  // reject session IDs the server did not create

    // Visitors who never logged in don't get a session (and no cookie).
    // Otherwise every anonymous request would send a new empty cookie, and one
    // arriving just after a login could overwrite the logged-in cookie.
    if (isset($_COOKIE[session_name()])) {
        session_start();
    }
}
