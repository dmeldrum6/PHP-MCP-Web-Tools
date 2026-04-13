<?php
/**
 * tools.php — Tool Registry & Dispatcher
 *
 * Defines the tools this MCP server exposes, and routes
 * incoming tool/call requests to the correct handler.
 *
 * To add a new tool:
 *   1. Add its definition to get_tool_definitions()
 *   2. Add a case for it in dispatch_tool()
 *   3. Implement the handler function (here or in tools_extended.php)
 */

require_once __DIR__ . '/tools_extended.php';


/**
 * Returns the list of tool definitions advertised to LM Studio.
 * These follow the MCP / JSON Schema format.
 */
function get_tool_definitions(): array {
    return [

        // ---------------------------------------------------------------
        // Tool 1: fetch_webpage
        // Fetches a single URL and returns its cleaned text content.
        // ---------------------------------------------------------------
        [
            'name'        => 'fetch_webpage',
            'description' =>
                'Fetches the readable text content of a public webpage given its URL. ' .
                'Use this when you need to read the actual content of a specific page ' .
                'the user has referenced or that would help answer their question. ' .
                'Returns cleaned plain text extracted from the page HTML.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'url' => [
                        'type'        => 'string',
                        'description' => 'The full URL of the webpage to fetch (must start with http:// or https://).',
                    ],
                    'extract_mode' => [
                        'type'        => 'string',
                        'enum'        => ['full', 'summary'],
                        'description' =>
                            'How much content to return. ' .
                            '"full" returns all extractable text (up to the server limit). ' .
                            '"summary" returns only the first ~1000 characters — useful for a quick preview.',
                        'default'     => 'full',
                    ],
                ],
                'required' => ['url'],
            ],
        ],

        // ---------------------------------------------------------------
        // Tool 2: fetch_links
        // Fetches a URL and returns the list of hyperlinks found on the page.
        // Useful for navigation / crawling a site structure.
        // ---------------------------------------------------------------
        [
            'name'        => 'fetch_links',
            'description' =>
                'Fetches a webpage and returns all hyperlinks found on it. ' .
                'Use this to discover what pages or resources a site links to, ' .
                'or to help navigate a site before fetching a specific page.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'url' => [
                        'type'        => 'string',
                        'description' => 'The full URL of the webpage to extract links from.',
                    ],
                    'filter' => [
                        'type'        => 'string',
                        'description' =>
                            'Optional keyword filter. Only links whose URL or link text contains ' .
                            'this string (case-insensitive) will be returned.',
                    ],
                ],
                'required' => ['url'],
            ],
        ],

        // ---------------------------------------------------------------
        // Tool 3: datetime_tool
        // Date/time queries, timezone conversion, date arithmetic.
        // ---------------------------------------------------------------
        [
            'name'        => 'datetime_tool',
            'description' =>
                'Performs date and time operations with full timezone support. ' .
                'Use this for: current date/time in any timezone, converting times between ' .
                'timezones, calculating the difference between two dates, adding or subtracting ' .
                'time intervals, and parsing or formatting date strings. ' .
                'Always use this tool for any question involving current time or date arithmetic ' .
                'rather than relying on your own knowledge.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'action' => [
                        'type'        => 'string',
                        'enum'        => ['now', 'convert', 'diff', 'add', 'parse', 'format'],
                        'description' =>
                            '"now" = current date/time. ' .
                            '"convert" = convert datetime from one timezone to another (requires input + input2). ' .
                            '"diff" = difference between two dates (requires input + input2). ' .
                            '"add" = add/subtract interval from a date (requires input + input2, e.g. input2="+3 months"). ' .
                            '"parse" = parse and describe a date string. ' .
                            '"format" = format a date using a PHP date format string.',
                        'default'     => 'now',
                    ],
                    'timezone' => [
                        'type'        => 'string',
                        'description' => 'IANA timezone name, e.g. "America/New_York", "Europe/London", "Asia/Tokyo". Defaults to UTC.',
                        'default'     => 'UTC',
                    ],
                    'input' => [
                        'type'        => 'string',
                        'description' => 'Primary date/time input string (for convert, diff, add, parse, format actions).',
                    ],
                    'input2' => [
                        'type'        => 'string',
                        'description' => 'Secondary input: target timezone (convert), second date (diff), interval (add), or target timezone (convert).',
                    ],
                    'format' => [
                        'type'        => 'string',
                        'description' => 'PHP date format string for the "format" action, e.g. "Y-m-d" or "D, d M Y H:i:s".',
                    ],
                ],
                'required' => ['action'],
            ],
        ],

        // ---------------------------------------------------------------
        // Tool 4: dns_lookup
        // DNS record queries, HTTP header inspection, hostname resolution.
        // ---------------------------------------------------------------
        [
            'name'        => 'dns_lookup',
            'description' =>
                'Performs DNS lookups and HTTP header inspection for a domain or URL. ' .
                'Use this to look up DNS records (A, MX, TXT, NS, CNAME), resolve hostnames ' .
                'to IP addresses, perform reverse DNS lookups, check HTTP response headers, ' .
                'or verify whether a URL is reachable and what status code it returns. ' .
                'Requires no external API — uses PHP\'s built-in DNS functions.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'action' => [
                        'type'        => 'string',
                        'enum'        => ['all', 'a', 'mx', 'txt', 'ns', 'cname', 'resolve', 'reverse', 'headers', 'status'],
                        'description' =>
                            '"all" = all common DNS records. ' .
                            '"a" = IPv4 address records. ' .
                            '"mx" = mail server records. ' .
                            '"txt" = TXT records (SPF, DKIM, verification). ' .
                            '"ns" = nameserver records. ' .
                            '"cname" = canonical name records. ' .
                            '"resolve" = hostname to IP. ' .
                            '"reverse" = IP to hostname. ' .
                            '"headers" = HTTP response headers (no body). ' .
                            '"status" = HTTP status code and redirect chain.',
                        'default'     => 'all',
                    ],
                    'host' => [
                        'type'        => 'string',
                        'description' => 'Domain or hostname to query, e.g. "example.com" or "mail.example.com". For "reverse", supply an IP address.',
                    ],
                    'url' => [
                        'type'        => 'string',
                        'description' => 'Full URL for "headers" and "status" actions, e.g. "https://example.com/page".',
                    ],
                ],
                'required' => ['action'],
            ],
        ],

        // ---------------------------------------------------------------
        // Tool 5: text_stats
        // Word counts, readability, string similarity, hashing, encoding.
        // ---------------------------------------------------------------
        [
            'name'        => 'text_stats',
            'description' =>
                'Analyses text or performs string operations with guaranteed accuracy. ' .
                'Use this for: word/character/sentence counts, readability scores, ' .
                'comparing how similar two strings are (with Levenshtein distance and ' .
                'phonetic matching), encoding/decoding text (base64, URL, HTML, hex), ' .
                'and hashing strings (SHA-256, MD5, etc.). ' .
                'Prefer this tool over your own calculations for anything requiring precise counts or hashes.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'action' => [
                        'type'        => 'string',
                        'enum'        => ['stats', 'similarity', 'encode', 'hash', 'wordcount'],
                        'description' =>
                            '"stats" = full statistics + readability score for a block of text. ' .
                            '"similarity" = compare two strings (requires text + text2). ' .
                            '"encode" = encode or decode text (requires text + encoding). ' .
                            '"hash" = hash text with a given algorithm (requires text + algorithm). ' .
                            '"wordcount" = quick word/character/line count.',
                        'default'     => 'stats',
                    ],
                    'text' => [
                        'type'        => 'string',
                        'description' => 'The primary text to analyse, encode, hash, or compare.',
                    ],
                    'text2' => [
                        'type'        => 'string',
                        'description' => 'Second text for the "similarity" action.',
                    ],
                    'encoding' => [
                        'type'        => 'string',
                        'enum'        => ['base64', 'base64decode', 'url', 'urldecode', 'html', 'htmldecode', 'hex', 'hexdecode', 'rot13'],
                        'description' => 'Encoding/decoding method for the "encode" action.',
                        'default'     => 'base64',
                    ],
                    'algorithm' => [
                        'type'        => 'string',
                        'description' => 'Hash algorithm for the "hash" action, e.g. "sha256", "md5", "sha512", "sha3-256".',
                        'default'     => 'sha256',
                    ],
                ],
                'required' => ['action', 'text'],
            ],
        ],

    ];
}


/**
 * Routes a tool call to the appropriate handler function.
 *
 * Returns a MCP-compliant tool result:
 * [
 *   'content' => [ ['type' => 'text', 'text' => '...'] ],
 *   'isError'  => false,
 * ]
 */
function dispatch_tool(string $tool_name, array $arguments): array {
    return match ($tool_name) {
        'fetch_webpage' => tool_fetch_webpage($arguments),
        'fetch_links'   => tool_fetch_links($arguments),
        'datetime_tool' => tool_datetime($arguments),
        'dns_lookup'    => tool_dns_lookup($arguments),
        'text_stats'    => tool_text_stats($arguments),
        default         => tool_error("Unknown tool: {$tool_name}"),
    };
}


// ---------------------------------------------------------------------------
// Tool Handlers
// ---------------------------------------------------------------------------

function tool_fetch_webpage(array $args): array {
    $url          = trim($args['url'] ?? '');
    $extract_mode = $args['extract_mode'] ?? 'full';

    if (empty($url)) {
        return tool_error('Missing required parameter: url');
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return tool_error("Invalid URL format: {$url}");
    }

    // Domain allowlist/blocklist check
    $domain_check = check_domain($url);
    if ($domain_check !== true) {
        return tool_error($domain_check);
    }

    // Fetch the page
    [$html, $fetch_error] = fetch_url($url);
    if ($fetch_error) {
        return tool_error("Failed to fetch URL: {$fetch_error}");
    }

    // Extract clean text
    $text = extract_text($html);

    // Apply extract_mode trimming
    if ($extract_mode === 'summary') {
        $text = mb_substr($text, 0, 1000);
        $text .= "\n\n[Summary mode: showing first 1000 characters only]";
    } elseif (mb_strlen($text) > MCP_MAX_TEXT_LENGTH) {
        $text  = mb_substr($text, 0, MCP_MAX_TEXT_LENGTH);
        $text .= "\n\n[Content trimmed to " . MCP_MAX_TEXT_LENGTH . " characters due to server limit]";
    }

    $output = "URL: {$url}\n";
    $output .= str_repeat('-', 60) . "\n";
    $output .= $text;

    return tool_success($output);
}


function tool_fetch_links(array $args): array {
    $url    = trim($args['url'] ?? '');
    $filter = strtolower(trim($args['filter'] ?? ''));

    if (empty($url)) {
        return tool_error('Missing required parameter: url');
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return tool_error("Invalid URL format: {$url}");
    }

    $domain_check = check_domain($url);
    if ($domain_check !== true) {
        return tool_error($domain_check);
    }

    [$html, $fetch_error] = fetch_url($url);
    if ($fetch_error) {
        return tool_error("Failed to fetch URL: {$fetch_error}");
    }

    $links = extract_links($html, $url);

    // Apply optional filter
    if (!empty($filter)) {
        $links = array_filter($links, function ($link) use ($filter) {
            return str_contains(strtolower($link['href']), $filter)
                || str_contains(strtolower($link['text']), $filter);
        });
        $links = array_values($links);
    }

    if (empty($links)) {
        $msg = empty($filter)
            ? "No links found on: {$url}"
            : "No links matching '{$filter}' found on: {$url}";
        return tool_success($msg);
    }

    // Format output
    $output  = "Links found on: {$url}\n";
    $output .= str_repeat('-', 60) . "\n";
    foreach ($links as $i => $link) {
        $num   = $i + 1;
        $text  = $link['text'] ?: '(no text)';
        $href  = $link['href'];
        $output .= "{$num}. {$text}\n   {$href}\n";
    }
    $output .= "\nTotal: " . count($links) . " link(s)";

    return tool_success($output);
}


// ---------------------------------------------------------------------------
// Response Builders
// ---------------------------------------------------------------------------

function tool_success(string $text): array {
    return [
        'content' => [['type' => 'text', 'text' => $text]],
        'isError' => false,
    ];
}

function tool_error(string $message): array {
    return [
        'content' => [['type' => 'text', 'text' => "Error: {$message}"]],
        'isError' => true,
    ];
}
