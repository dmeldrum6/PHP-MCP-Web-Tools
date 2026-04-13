<?php
/**
 * auth.php — Request Authentication
 *
 * Validates the shared secret API key sent by LM Studio in the
 * X-API-Key header (or Authorization: Bearer header as a fallback).
 */

function verify_auth(): bool {
    // If auth is disabled (dev mode), always pass
    if (!MCP_AUTH_ENABLED) {
        return true;
    }

    $expected = MCP_API_KEY;

    // Check X-API-Key header (preferred)
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';

    // Fallback: Authorization: Bearer <key>
    if (empty($key)) {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($auth_header, 'Bearer ')) {
            $key = substr($auth_header, 7);
        }
    }

    if (empty($key)) {
        return false;
    }

    // Use hash_equals to prevent timing attacks
    return hash_equals($expected, $key);
}
