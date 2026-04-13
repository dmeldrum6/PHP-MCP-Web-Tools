<?php
/**
 * config.php — MCP Web Fetch Server Configuration
 *
 * Copy this file and fill in your values.
 * Keep this file OUTSIDE your web root if possible, or ensure
 * your host blocks direct access to .php config files.
 */

// ---------------------------------------------------------------------------
// SECURITY
// ---------------------------------------------------------------------------

/**
 * Shared secret key. LM Studio (or any client) must send this in the header:
 *   X-API-Key: your-secret-key-here
 *
 * Generate a strong random key, e.g.:
 *   php -r "echo bin2hex(random_bytes(32));"
 */
define('MCP_API_KEY', 'REPLACE_WITH_YOUR_SECRET_KEY');

/**
 * Set to true to enforce the API key check.
 * Set to false only for local testing — never in production.
 */
define('MCP_AUTH_ENABLED', true);


// ---------------------------------------------------------------------------
// ALLOWED DOMAINS (URL allowlist)
// ---------------------------------------------------------------------------

/**
 * Restrict which domains the fetch tool is allowed to retrieve.
 * This prevents the LLM from using your server to access internal
 * network resources or unintended sites.
 *
 * Leave as an empty array [] to allow ALL public URLs (less safe).
 *
 * Examples:
 *   'wikipedia.org'
 *   'en.wikipedia.org'   <- subdomain specific
 *   'github.com'
 */
define('MCP_ALLOWED_DOMAINS', [
    // 'wikipedia.org',
    // 'github.com',
    // Add your allowed domains here, or leave empty to allow all.
]);

/**
 * Always block these domains, even if MCP_ALLOWED_DOMAINS is empty.
 * Protects against SSRF (Server-Side Request Forgery) by blocking
 * private/internal network addresses.
 */
define('MCP_BLOCKED_DOMAINS', [
    'localhost',
    '127.0.0.1',
    '0.0.0.0',
    '169.254.169.254', // AWS/cloud metadata endpoint
    '10.',             // RFC1918 private range prefix check (see fetcher.php)
    '192.168.',
    '172.16.',
]);


// ---------------------------------------------------------------------------
// FETCH SETTINGS
// ---------------------------------------------------------------------------

/** Maximum size of a fetched page to process (bytes). Default: 500KB */
define('MCP_MAX_FETCH_BYTES', 512000);

/** cURL request timeout in seconds */
define('MCP_FETCH_TIMEOUT', 15);

/** User-agent sent with fetch requests */
define('MCP_USER_AGENT', 'MCP-WebFetch/1.0 (LLM Tool; +https://yoursite.com)');

/**
 * Maximum length of extracted text returned to the LLM (characters).
 * LLMs have context limits — trim large pages to keep responses useful.
 * ~8000 chars ≈ ~2000 tokens, a safe default for most local models.
 */
define('MCP_MAX_TEXT_LENGTH', 8000);


// ---------------------------------------------------------------------------
// SSE / CONNECTION
// ---------------------------------------------------------------------------

/** How long to keep the SSE connection alive (seconds). */
define('MCP_SSE_TIMEOUT', 55);
