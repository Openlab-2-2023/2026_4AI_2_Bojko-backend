<?php
// Checks who is logged in. Endpoints that need a user call requireLogin().
// The user id always comes from the session, never from the request body,
// so nobody can pretend to be another user.

function currentUserId(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

// Returns the logged-in user's id, or stops the request with 401.
function requireLogin(): int
{
    $userId = currentUserId();
    if ($userId === null) {
        sendError('Na túto akciu sa musíte prihlásiť.', 401);
    }
    return $userId;
}

// Called after a successful login or registration.
function startUserSession(int $userId): void
{
    // A new session ID after login prevents "session fixation":
    // an attacker can't plant a session ID before login and reuse it after.
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

function endUserSession(): void
{
    $_SESSION = [];
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => $cookie['path'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'],
    ]);
    session_destroy();
}
