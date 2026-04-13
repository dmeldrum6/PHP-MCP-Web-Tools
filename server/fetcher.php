<?php
/**
 * fetcher.php — HTTP Fetching & Content Extraction
 *
 * Handles:
 *  - Domain allowlist / blocklist validation
 *  - cURL-based page fetching with safety limits
 *  - HTML → plain text extraction
 *  - Link extraction from HTML
 */


// ---------------------------------------------------------------------------
// DOMAIN VALIDATION
// ---------------------------------------------------------------------------

/**
 * Checks if a URL's domain is permitted by config.
 * Returns true if allowed, or an error message string if not.
 */
function check_domain(string $url): true|string {
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

    if (empty($host)) {
        return "Could not parse host from URL.";
    }

    // Scheme check — only allow http and https
    $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) {
        return "Only http and https URLs are permitted.";
    }

    // Block private/internal addresses (SSRF protection)
    foreach (MCP_BLOCKED_DOMAINS as $blocked) {
        if (str_starts_with($host, $blocked) || $host === $blocked) {
            return "Access to '{$host}' is blocked for security reasons.";
        }
    }

    // Block raw IP addresses (further SSRF protection)
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return "Direct IP address access is not permitted.";
    }

    // If an allowlist is configured, enforce it
    $allowed = MCP_ALLOWED_DOMAINS;
    if (!empty($allowed)) {
        $permitted = false;
        foreach ($allowed as $domain) {
            $domain = strtolower($domain);
            // Match exact domain or any subdomain
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                $permitted = true;
                break;
            }
        }
        if (!$permitted) {
            return "Domain '{$host}' is not on the allowed list.";
        }
    }

    return true;
}


// ---------------------------------------------------------------------------
// PAGE FETCHING
// ---------------------------------------------------------------------------

/**
 * Fetches a URL using cURL with safety limits applied.
 *
 * @return array [string $html, string|null $error]
 *               On success: [$html, null]
 *               On failure: ['', 'error message']
 */
function fetch_url(string $url): array {
    if (!function_exists('curl_init')) {
        return ['', 'cURL is not available on this server.'];
    }

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,       // Follow redirects
        CURLOPT_MAXREDIRS      => 5,          // But not forever
        CURLOPT_TIMEOUT        => MCP_FETCH_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => MCP_USER_AGENT,
        CURLOPT_ENCODING       => '',         // Accept compressed responses
        CURLOPT_SSL_VERIFYPEER => true,       // Always verify SSL
        CURLOPT_SSL_VERIFYHOST => 2,

        // Limit response size — stop reading after MCP_MAX_FETCH_BYTES
        CURLOPT_BUFFERSIZE     => 128000,
        CURLOPT_NOPROGRESS     => false,
        CURLOPT_PROGRESSFUNCTION => function (
            $resource, $downloadSize, $downloaded
        ) {
            // Abort if we exceed the max fetch size
            if ($downloaded > MCP_MAX_FETCH_BYTES) {
                return 1; // Non-zero aborts the transfer
            }
            return 0;
        },

        // Only accept HTML-like content types
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
        ],
    ]);

    $html      = curl_exec($ch);
    $error     = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($html === false || !empty($error)) {
        return ['', $error ?: 'Unknown cURL error'];
    }

    if ($http_code >= 400) {
        return ['', "HTTP {$http_code} error from server"];
    }

    return [$html, null];
}


// ---------------------------------------------------------------------------
// TEXT EXTRACTION
// ---------------------------------------------------------------------------

/**
 * Converts HTML to clean, readable plain text.
 *
 * Strategy:
 *  1. Remove scripts, styles, nav, footer (noise)
 *  2. Convert block-level elements to newlines
 *  3. Strip remaining tags
 *  4. Normalize whitespace
 */
function extract_text(string $html): string {
    if (empty(trim($html))) {
        return '(No content returned)';
    }

    // Suppress libxml warnings from malformed HTML
    $prev = libxml_use_internal_errors(true);

    $doc = new DOMDocument();
    $doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    // Remove noisy elements before text extraction
    $remove_tags = ['script', 'style', 'noscript', 'nav', 'footer',
                    'header', 'aside', 'form', 'button', 'iframe',
                    'svg', 'img', 'figure', 'figcaption'];

    foreach ($remove_tags as $tag) {
        $nodes = $doc->getElementsByTagName($tag);
        // Iterate in reverse — live NodeList shrinks as we remove nodes
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $node = $nodes->item($i);
            $node->parentNode?->removeChild($node);
        }
    }

    // Get the body text, or fall back to full document
    $body = $doc->getElementsByTagName('body')->item(0);
    $text = $body ? node_to_text($doc, $body) : $doc->textContent;

    // Normalize whitespace
    $text = preg_replace('/[ \t]+/', ' ', $text);           // Collapse spaces/tabs
    $text = preg_replace('/\n{3,}/', "\n\n", $text);        // Max 2 blank lines
    $text = trim($text);

    // Attempt to extract the page title and prepend it
    $titles = $doc->getElementsByTagName('title');
    if ($titles->length > 0) {
        $title = trim($titles->item(0)->textContent);
        if (!empty($title)) {
            $text = "Page Title: {$title}\n\n" . $text;
        }
    }

    return $text ?: '(Could not extract readable text from this page)';
}


/**
 * Recursively walks a DOM node and converts it to text,
 * inserting newlines at block-level elements.
 */
function node_to_text(DOMDocument $doc, DOMNode $node): string {
    $block_elements = [
        'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'li', 'tr', 'br', 'hr', 'blockquote', 'pre',
        'article', 'section', 'main',
    ];

    $text = '';

    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE) {
            $text .= $child->textContent;
        } elseif ($child->nodeType === XML_ELEMENT_NODE) {
            $tag    = strtolower($child->nodeName);
            $inner  = node_to_text($doc, $child);

            if (in_array($tag, $block_elements, true)) {
                $text .= "\n" . $inner . "\n";
            } elseif ($tag === 'a') {
                // Keep link text but add href in brackets if useful
                $href = $child->getAttribute('href');
                $text .= $inner;
                // Only annotate if href is a meaningful URL (not '#' or JS)
                if (!empty($href) && !str_starts_with($href, '#')
                    && !str_starts_with($href, 'javascript')) {
                    $text .= " [{$href}]";
                }
            } else {
                $text .= $inner;
            }
        }
    }

    return $text;
}


// ---------------------------------------------------------------------------
// LINK EXTRACTION
// ---------------------------------------------------------------------------

/**
 * Extracts all links from HTML, resolving relative URLs against a base URL.
 *
 * @return array  Array of ['href' => '...', 'text' => '...']
 */
function extract_links(string $html, string $base_url): array {
    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    $doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $anchors = $doc->getElementsByTagName('a');
    $links   = [];
    $seen    = [];

    $base_parts = parse_url($base_url);
    $base_origin = ($base_parts['scheme'] ?? 'https') . '://' . ($base_parts['host'] ?? '');

    foreach ($anchors as $anchor) {
        $href = trim($anchor->getAttribute('href'));
        $text = trim(preg_replace('/\s+/', ' ', $anchor->textContent));

        // Skip empty, anchor-only, or JS links
        if (empty($href) || $href === '#' || str_starts_with($href, 'javascript')) {
            continue;
        }

        // Resolve relative URLs
        if (str_starts_with($href, '//')) {
            $href = ($base_parts['scheme'] ?? 'https') . ':' . $href;
        } elseif (str_starts_with($href, '/')) {
            $href = $base_origin . $href;
        } elseif (!str_starts_with($href, 'http')) {
            // Relative path — resolve against base directory
            $base_dir = $base_origin . dirname($base_parts['path'] ?? '/') . '/';
            $href = $base_dir . $href;
        }

        // Deduplicate
        if (isset($seen[$href])) {
            continue;
        }
        $seen[$href] = true;

        $links[] = ['href' => $href, 'text' => $text];
    }

    return $links;
}
