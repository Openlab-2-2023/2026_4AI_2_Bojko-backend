<?php
// Database queries for users. Passwords are only ever stored as a hash.

function findUserById(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: null;
}

// Login accepts either the username or the e-mail address.
// (The table collation is case-insensitive, so "Jana_K" finds "jana_k".)
function findUserByLogin(string $login): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = :username OR email = :email LIMIT 1');
    $stmt->execute(['username' => $login, 'email' => $login]);
    return $stmt->fetch() ?: null;
}

// Returns which of the two values are already taken, e.g. ['email' => true].
function findTakenFields(string $username, string $email): array
{
    $stmt = db()->prepare('SELECT username, email FROM users WHERE username = :username OR email = :email');
    $stmt->execute(['username' => $username, 'email' => $email]);

    $taken = [];
    foreach ($stmt->fetchAll() as $row) {
        if (mb_strtolower($row['username']) === mb_strtolower($username)) {
            $taken['username'] = true;
        }
        if (mb_strtolower($row['email']) === mb_strtolower($email)) {
            $taken['email'] = true;
        }
    }
    return $taken;
}

// Creates a user and returns the new id.
function createUser(string $username, string $email, string $password, string $fullName, string $location): int
{
    $stmt = db()->prepare(
        'INSERT INTO users (username, email, password_hash, full_name, location)
         VALUES (:username, :email, :password_hash, :full_name, :location)'
    );
    $stmt->execute([
        'username'      => $username,
        'email'         => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),  // bcrypt + random salt
        'full_name'     => $fullName,
        'location'      => $location,
    ]);
    return (int) db()->lastInsertId();
}

// The user as sent to Angular. The password hash is never included.
function formatUser(array $row): array
{
    return [
        'id'        => (int) $row['id'],
        'username'  => $row['username'],
        'email'     => $row['email'],
        'fullName'  => $row['full_name'],
        'location'  => $row['location'],
        'createdAt' => toIsoDate($row['created_at']),
    ];
}
