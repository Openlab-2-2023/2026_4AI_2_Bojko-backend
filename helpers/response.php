<?php
// Helpers for sending JSON responses and reading request data.
// Every endpoint answers in the same format:
//   success → the data itself, e.g. { "id": 1, "title": "..." }
//   error   → { "error": "Human readable message" } with a 4xx/5xx status code

function sendJson(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// $fields can name the form fields that are wrong, e.g. ['email' => 'Neplatný e-mail.'],
// so Angular can show each message next to the right input.
function sendError(string $message, int $status, array $fields = []): never
{
    $body = ['error' => $message];
    if ($fields) {
        $body['fields'] = $fields;
    }
    sendJson($body, $status);
}

// Reads the JSON body of a POST request and returns it as an array.
// Only JSON is accepted: a normal HTML form on another website cannot send
// Content-Type: application/json, which helps protect against CSRF.
function readJsonBody(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (!str_starts_with($contentType, 'application/json')) {
        sendError('Požiadavka musí byť vo formáte JSON.', 415);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        sendError('Neplatné JSON dáta.', 400);
    }
    return $data;
}

// Reads a text value from a JSON body; anything that is not a string becomes ''.
function bodyString(array $body, string $key): string
{
    return is_string($body[$key] ?? null) ? trim($body[$key]) : '';
}

// Reads an integer from the query string (?page=2) and keeps it within limits.
function queryInt(string $name, int $default, int $min, int $max): int
{
    $value = filter_input(INPUT_GET, $name, FILTER_VALIDATE_INT);
    if ($value === null || $value === false) {
        return $default;
    }
    return max($min, min($max, $value));
}

// Reads a string from the query string, or returns the default.
function queryString(string $name, string $default = ''): string
{
    $value = $_GET[$name] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

// Converts a MySQL DATETIME ("2026-11-09 18:30:00") to ISO 8601 for Angular.
function toIsoDate(?string $mysqlDate): ?string
{
    return $mysqlDate === null ? null : (new DateTime($mysqlDate))->format(DATE_ATOM);
}

// Thrown by services when a request breaks a business rule
// (e.g. "the auction has ended"). index.php turns it into a JSON error,
// so a service never has to know about HTTP responses itself.
class ApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status, public readonly array $fields = [])
    {
        parent::__construct($message);
    }
}
