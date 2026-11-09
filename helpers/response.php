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

function sendError(string $message, int $status): never
{
    sendJson(['error' => $message], $status);
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
