<?php
// Endpoints for registration, login and logout.

// POST /api/auth/register
// Body: { username, email, password, fullName, location }
function register(): never
{
    $body     = readJsonBody();
    $username = bodyString($body, 'username');
    $email    = mb_strtolower(bodyString($body, 'email'));
    $fullName = bodyString($body, 'fullName');
    $location = bodyString($body, 'location');
    // The password is not trimmed – spaces are allowed in passwords.
    $password = is_string($body['password'] ?? null) ? $body['password'] : '';

    // 1) Check every field and collect all problems at once.
    $errors = [];
    if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $errors['username'] = 'Meno musí mať 3–30 znakov: písmená bez diakritiky, čísla alebo _.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
        $errors['email'] = 'Zadajte platnú e-mailovú adresu.';
    }
    // bcrypt only uses the first 72 bytes, so longer passwords are refused.
    if (strlen($password) < 8 || strlen($password) > 72) {
        $errors['password'] = 'Heslo musí mať 8–72 znakov.';
    }
    if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
        $errors['fullName'] = 'Zadajte meno a priezvisko (2–100 znakov).';
    }
    if (mb_strlen($location) < 2 || mb_strlen($location) > 100) {
        $errors['location'] = 'Zadajte lokalitu (2–100 znakov).';
    }
    if ($errors) {
        sendError('Skontrolujte vyplnené údaje.', 422, $errors);
    }

    // 2) Username and e-mail must be unique.
    $taken = findTakenFields($username, $email);
    if ($taken) {
        $fields = [];
        if (isset($taken['username'])) {
            $fields['username'] = 'Toto používateľské meno je už obsadené.';
        }
        if (isset($taken['email'])) {
            $fields['email'] = 'Účet s týmto e-mailom už existuje.';
        }
        sendError('Účet už existuje.', 409, $fields);
    }

    // 3) Create the account and log the new user in.
    try {
        $userId = createUser($username, $email, $password, $fullName, $location);
    } catch (PDOException $e) {
        // Two people registered the same name at the same moment:
        // the UNIQUE index in the database rejected the second one.
        if ($e->getCode() === '23000') {
            sendError('Účet už existuje.', 409);
        }
        throw $e;
    }

    startUserSession($userId);
    sendJson(formatUser(findUserById($userId)), 201);
}

// POST /api/auth/login
// Body: { login, password }   (login = username or e-mail)
function login(): never
{
    $body     = readJsonBody();
    $login    = bodyString($body, 'login');
    $password = is_string($body['password'] ?? null) ? $body['password'] : '';

    if ($login === '' || $password === '') {
        sendError('Zadajte meno alebo e-mail a heslo.', 422);
    }

    $user = findUserByLogin($login);

    // The same message for "user doesn't exist" and "wrong password",
    // so nobody can find out which usernames are registered.
    if ($user === null || !password_verify($password, $user['password_hash'])) {
        sendError('Nesprávne meno alebo heslo.', 401);
    }

    startUserSession((int) $user['id']);
    sendJson(formatUser($user));
}

// POST /api/auth/logout
function logout(): never
{
    endUserSession();
    sendJson(['loggedOut' => true]);
}

// GET /api/auth/me
// Returns the logged-in user, or null. Angular calls this when the app starts,
// so the user stays logged in after reloading the page.
function me(): never
{
    $userId = currentUserId();
    $user = $userId === null ? null : findUserById($userId);

    if ($userId !== null && $user === null) {
        endUserSession();   // the account no longer exists
    }
    sendJson($user === null ? null : formatUser($user));
}
