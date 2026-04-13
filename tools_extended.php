<?php
/**
 * tools_extended.php — Extended Tool Implementations
 *
 * Provides three additional MCP tools:
 *
 *   datetime_tool  — Current time, timezone conversion, date arithmetic,
 *                    date formatting and parsing
 *
 *   dns_lookup     — DNS record queries, HTTP header inspection,
 *                    hostname resolution
 *
 *   text_stats     — Word/character counts, readability, fuzzy string
 *                    matching, similarity scoring, encoding/decoding
 */


// =============================================================================
// TOOL: datetime_tool
// =============================================================================

function tool_datetime(array $args): array {
    $action   = trim($args['action']   ?? 'now');
    $timezone = trim($args['timezone'] ?? 'UTC');
    $input    = trim($args['input']    ?? '');
    $input2   = trim($args['input2']   ?? '');
    $format   = trim($args['format']   ?? '');

    // Validate timezone — fall back to UTC with a warning if invalid
    $tz_warning = '';
    try {
        $tz = new DateTimeZone($timezone);
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
        $tz_warning = "\n[Warning: Unknown timezone '{$timezone}', defaulted to UTC]";
    }

    switch ($action) {

        // --- Current date and time ---
        case 'now': {
            $dt  = new DateTimeImmutable('now', $tz);
            $out = format_datetime_block($dt, $timezone);
            return tool_success($out . $tz_warning);
        }

        // --- Convert a datetime from one timezone to another ---
        case 'convert': {
            if (empty($input)) {
                return tool_error('datetime_tool convert: "input" (datetime string) is required.');
            }
            if (empty($input2)) {
                return tool_error('datetime_tool convert: "input2" (target timezone) is required.');
            }

            try {
                $src_tz  = new DateTimeZone($timezone);
                $dst_tz  = new DateTimeZone($input2);
                $dt      = new DateTimeImmutable($input, $src_tz);
                $dt_conv = $dt->setTimezone($dst_tz);

                $out  = "Input:     {$input} ({$timezone})\n";
                $out .= "Converted: " . $dt_conv->format('Y-m-d H:i:s') . " ({$input2})\n";
                $out .= "Day:       " . $dt_conv->format('l') . "\n";
                $out .= "UTC offset source:  " . $dt->format('P') . "\n";
                $out .= "UTC offset target:  " . $dt_conv->format('P');
                return tool_success($out . $tz_warning);
            } catch (Exception $e) {
                return tool_error("datetime_tool convert: " . $e->getMessage());
            }
        }

        // --- Difference between two dates ---
        case 'diff': {
            if (empty($input) || empty($input2)) {
                return tool_error('datetime_tool diff: both "input" and "input2" (date strings) are required.');
            }
            try {
                $dt1  = new DateTimeImmutable($input,  $tz);
                $dt2  = new DateTimeImmutable($input2, $tz);
                $diff = $dt1->diff($dt2);

                $past_future = $diff->invert ? 'in the past' : 'in the future';

                $out  = "Date 1: " . $dt1->format('Y-m-d H:i:s') . "\n";
                $out .= "Date 2: " . $dt2->format('Y-m-d H:i:s') . "\n";
                $out .= str_repeat('-', 40) . "\n";
                $out .= "Difference ({$past_future}):\n";
                $out .= "  {$diff->y} year(s), {$diff->m} month(s), {$diff->d} day(s)\n";
                $out .= "  {$diff->h} hour(s), {$diff->i} minute(s), {$diff->s} second(s)\n";
                $out .= "Total days apart: " . abs($diff->days) . "\n";

                // Working days (Mon–Fri) approximation
                $working_days = count_working_days($dt1, $dt2);
                $out .= "Working days apart (approx, no holidays): {$working_days}";
                return tool_success($out . $tz_warning);
            } catch (Exception $e) {
                return tool_error("datetime_tool diff: " . $e->getMessage());
            }
        }

        // --- Add or subtract an interval from a date ---
        case 'add': {
            // input = starting date, input2 = interval string e.g. "P30D", "+3 months"
            if (empty($input) || empty($input2)) {
                return tool_error('datetime_tool add: "input" (start date) and "input2" (interval, e.g. "+3 months" or "P30D") are required.');
            }
            try {
                $dt = new DateTimeImmutable($input, $tz);

                // Accept both DateInterval strings (P30D) and natural language (+3 months)
                if (str_starts_with(strtoupper(trim($input2)), 'P')) {
                    $interval = new DateInterval($input2);
                    $result   = $dt->add($interval);
                } else {
                    $result = new DateTimeImmutable($input2, $tz);
                    // Treat input2 as a modifier applied to input
                    $result = $dt->modify($input2);
                    if ($result === false) {
                        return tool_error("Could not parse interval: '{$input2}'");
                    }
                }

                $out  = "Start:  " . $dt->format('Y-m-d H:i:s') . " ({$timezone})\n";
                $out .= "Adding: {$input2}\n";
                $out .= "Result: " . $result->format('Y-m-d H:i:s') . "\n";
                $out .= "Day:    " . $result->format('l, F j, Y');
                return tool_success($out . $tz_warning);
            } catch (Exception $e) {
                return tool_error("datetime_tool add: " . $e->getMessage());
            }
        }

        // --- Parse and describe a date string ---
        case 'parse': {
            if (empty($input)) {
                return tool_error('datetime_tool parse: "input" (date string to parse) is required.');
            }
            try {
                $dt  = new DateTimeImmutable($input, $tz);
                $out = format_datetime_block($dt, $timezone);
                return tool_success($out . $tz_warning);
            } catch (Exception $e) {
                return tool_error("datetime_tool parse: Could not parse '{$input}': " . $e->getMessage());
            }
        }

        // --- Format a date in a specific format ---
        case 'format': {
            if (empty($input)) {
                return tool_error('datetime_tool format: "input" (date string) is required.');
            }
            $fmt = !empty($format) ? $format : 'l, F j, Y \a\t g:i A T';
            try {
                $dt  = new DateTimeImmutable($input, $tz);
                $out = "Input:    {$input}\n";
                $out .= "Timezone: {$timezone}\n";
                $out .= "Format:   {$fmt}\n";
                $out .= "Result:   " . $dt->format($fmt);
                return tool_success($out . $tz_warning);
            } catch (Exception $e) {
                return tool_error("datetime_tool format: " . $e->getMessage());
            }
        }

        default:
            return tool_error(
                "Unknown datetime action: '{$action}'. " .
                "Valid actions: now, convert, diff, add, parse, format"
            );
    }
}

/** Formats a full descriptive block for a DateTimeImmutable */
function format_datetime_block(DateTimeImmutable $dt, string $tz_name): string {
    $unix     = $dt->getTimestamp();
    $week_num = $dt->format('W');
    $day_year = $dt->format('z') + 1;
    $leap     = (int)$dt->format('L') ? 'Yes' : 'No';

    return implode("\n", [
        "Date/Time:    " . $dt->format('Y-m-d H:i:s'),
        "Timezone:     {$tz_name} (UTC offset " . $dt->format('P') . ")",
        "Day of week:  " . $dt->format('l'),
        "Full date:    " . $dt->format('F j, Y'),
        "12-hour time: " . $dt->format('g:i:s A'),
        "Day of year:  {$day_year} of " . ($leap === 'Yes' ? '366' : '365'),
        "Week number:  {$week_num}",
        "Leap year:    {$leap}",
        "Unix timestamp: {$unix}",
    ]);
}

/** Count working days (Mon–Fri) between two DateTimeImmutable objects */
function count_working_days(DateTimeImmutable $start, DateTimeImmutable $end): int {
    if ($start > $end) [$start, $end] = [$end, $start];
    $count   = 0;
    $current = $start;
    while ($current <= $end) {
        $dow = (int)$current->format('N'); // 1=Mon, 7=Sun
        if ($dow < 6) $count++;
        $current = $current->modify('+1 day');
    }
    return $count;
}


// =============================================================================
// TOOL: dns_lookup
// =============================================================================

function tool_dns_lookup(array $args): array {
    $action = trim($args['action'] ?? 'all');
    $host   = trim($args['host']   ?? '');
    $url    = trim($args['url']    ?? '');

    // dns actions need a host; header actions need a url
    $needs_host = in_array($action, ['a', 'mx', 'txt', 'ns', 'cname', 'all', 'reverse', 'resolve']);
    $needs_url  = in_array($action, ['headers', 'status']);

    if ($needs_host && empty($host)) {
        return tool_error("dns_lookup '{$action}': 'host' parameter is required.");
    }
    if ($needs_url && empty($url)) {
        // Fall back to using host as URL if provided
        if (!empty($host)) {
            $url = (str_starts_with($host, 'http') ? $host : 'https://' . $host);
        } else {
            return tool_error("dns_lookup '{$action}': 'url' parameter is required.");
        }
    }

    // Strip protocol from host if user accidentally included it
    if (!empty($host)) {
        $host = strtolower(preg_replace('#^https?://#', '', $host));
        $host = rtrim(parse_url('http://' . $host, PHP_URL_HOST) ?? $host, '.');
    }

    switch ($action) {

        // --- All common DNS records ---
        case 'all': {
            $types   = ['A', 'AAAA', 'MX', 'NS', 'TXT', 'CNAME', 'SOA'];
            $out     = "DNS records for: {$host}\n" . str_repeat('-', 50) . "\n";
            $found   = false;
            foreach ($types as $type) {
                $records = @dns_get_record($host, constant("DNS_{$type}"));
                if (!empty($records)) {
                    $found = true;
                    $out  .= "\n[{$type}]\n";
                    $out  .= format_dns_records($records, $type);
                }
            }
            if (!$found) $out .= "No DNS records found.";
            return tool_success($out);
        }

        // --- Specific record types ---
        case 'a':
        case 'mx':
        case 'txt':
        case 'ns':
        case 'cname': {
            $type    = strtoupper($action);
            $const   = "DNS_{$type}";
            $records = @dns_get_record($host, constant($const));
            if (empty($records)) {
                return tool_success("No {$type} records found for: {$host}");
            }
            $out  = "{$type} records for: {$host}\n" . str_repeat('-', 50) . "\n";
            $out .= format_dns_records($records, $type);
            return tool_success($out);
        }

        // --- Resolve hostname to IP ---
        case 'resolve': {
            $ip = gethostbyname($host);
            if ($ip === $host) {
                return tool_success("Could not resolve hostname: {$host}");
            }
            // Also try IPv6
            $records = @dns_get_record($host, DNS_AAAA);
            $out  = "Hostname: {$host}\n";
            $out .= "IPv4:     {$ip}\n";
            if (!empty($records)) {
                $out .= "IPv6:     " . ($records[0]['ipv6'] ?? 'n/a') . "\n";
            }
            return tool_success($out);
        }

        // --- Reverse DNS (IP to hostname) ---
        case 'reverse': {
            // host field can be an IP for this action
            $hostname = gethostbyaddr($host);
            $out  = "IP Address: {$host}\n";
            $out .= "Reverse DNS: " . ($hostname !== $host ? $hostname : '(no PTR record found)');
            return tool_success($out);
        }

        // --- HTTP headers only (no body download) ---
        case 'headers': {
            $headers = @get_headers($url, associative: true);
            if (!$headers) {
                return tool_error("Could not retrieve headers from: {$url}");
            }
            $out  = "HTTP Headers for: {$url}\n" . str_repeat('-', 50) . "\n";
            foreach ($headers as $key => $value) {
                if (is_array($value)) $value = implode(', ', $value);
                $out .= "{$key}: {$value}\n";
            }
            return tool_success(rtrim($out));
        }

        // --- HTTP status code check ---
        case 'status': {
            $headers = @get_headers($url, associative: false);
            if (!$headers) {
                return tool_error("Could not connect to: {$url}");
            }
            // Status is in the first header line e.g. "HTTP/1.1 200 OK"
            $status_line = $headers[0] ?? 'Unknown';

            // Collect any redirects
            $redirects = array_filter($headers, fn($h) => str_starts_with($h, 'HTTP/'));
            $out  = "URL: {$url}\n";
            $out .= "Status: {$status_line}\n";
            if (count($redirects) > 1) {
                $out .= "Redirect chain (" . count($redirects) . " hops):\n";
                foreach ($redirects as $r) $out .= "  → {$r}\n";
            }
            return tool_success(rtrim($out));
        }

        default:
            return tool_error(
                "Unknown dns_lookup action: '{$action}'. " .
                "Valid actions: all, a, mx, txt, ns, cname, resolve, reverse, headers, status"
            );
    }
}

/** Formats an array of dns_get_record() results into readable text */
function format_dns_records(array $records, string $type): string {
    $out = '';
    foreach ($records as $r) {
        switch ($type) {
            case 'A':    $out .= "  {$r['ip']}\n"; break;
            case 'AAAA': $out .= "  {$r['ipv6']}\n"; break;
            case 'MX':   $out .= "  Priority {$r['pri']}  {$r['target']}\n"; break;
            case 'NS':   $out .= "  {$r['target']}\n"; break;
            case 'TXT':  $out .= "  " . implode(' ', (array)($r['txt'] ?? $r['entries'] ?? [])) . "\n"; break;
            case 'CNAME':$out .= "  {$r['target']}\n"; break;
            case 'SOA':
                $out .= "  Primary NS:  {$r['mname']}\n";
                $out .= "  Responsible: {$r['rname']}\n";
                $out .= "  Serial:      {$r['serial']}\n";
                $out .= "  Refresh:     {$r['refresh']}s\n";
                $out .= "  TTL:         {$r['minimum']}s\n";
                break;
            default:
                $out .= "  " . json_encode($r) . "\n";
        }
        if (isset($r['ttl'])) {
            $out .= "    TTL: {$r['ttl']}s\n";
        }
    }
    return $out;
}


// =============================================================================
// TOOL: text_stats
// =============================================================================

function tool_text_stats(array $args): array {
    $action = trim($args['action'] ?? 'stats');
    $text   = $args['text']   ?? '';
    $text2  = $args['text2']  ?? '';
    $encode = trim($args['encoding'] ?? 'base64');
    $algo   = trim($args['algorithm'] ?? 'sha256');

    switch ($action) {

        // --- Full statistics on a block of text ---
        case 'stats': {
            if (empty($text)) return tool_error('text_stats stats: "text" is required.');

            $char_count      = mb_strlen($text);
            $char_no_spaces  = mb_strlen(preg_replace('/\s/', '', $text));
            $word_count      = str_word_count($text);
            $line_count      = substr_count($text, "\n") + 1;
            $sentence_count  = preg_match_all('/[.!?]+(?:\s|$)/u', $text, $m);
            $paragraph_count = count(array_filter(preg_split('/\n{2,}/', trim($text))));
            $unique_words    = count(array_unique(
                array_map('strtolower', str_word_count($text, 1))
            ));

            // Flesch Reading Ease (approximate)
            $syllables = count_syllables_approx($text);
            $flesch    = ($word_count > 0 && $sentence_count > 0)
                ? round(206.835
                    - 1.015  * ($word_count / max(1, $sentence_count))
                    - 84.6   * ($syllables  / max(1, $word_count)), 1)
                : 'N/A';

            $reading_level = flesch_label($flesch);

            // Estimated reading time (average 238 wpm)
            $read_minutes = round($word_count / 238, 1);

            // Most frequent words (top 10, ignore stop words)
            $top_words = top_words($text, 10);

            $out  = "=== Text Statistics ===\n";
            $out .= "Characters (total):     {$char_count}\n";
            $out .= "Characters (no spaces): {$char_no_spaces}\n";
            $out .= "Words:                  {$word_count}\n";
            $out .= "Unique words:           {$unique_words}\n";
            $out .= "Sentences:              {$sentence_count}\n";
            $out .= "Lines:                  {$line_count}\n";
            $out .= "Paragraphs:             {$paragraph_count}\n";
            $out .= "Avg words/sentence:     " . round($word_count / max(1, $sentence_count), 1) . "\n";
            $out .= "\n=== Readability ===\n";
            $out .= "Flesch Reading Ease:    {$flesch}  ({$reading_level})\n";
            $out .= "Est. reading time:      {$read_minutes} min\n";
            $out .= "\n=== Top 10 Words ===\n";
            foreach ($top_words as $word => $count) {
                $out .= "  {$word}: {$count}\n";
            }

            return tool_success($out);
        }

        // --- Similarity between two strings ---
        case 'similarity': {
            if (empty($text) || empty($text2)) {
                return tool_error('text_stats similarity: both "text" and "text2" are required.');
            }

            similar_text($text, $text2, $percent);
            $lev          = levenshtein(mb_substr($text, 0, 255), mb_substr($text2, 0, 255));
            $soundex_a    = soundex($text);
            $soundex_b    = soundex($text2);
            $soundex_match = ($soundex_a === $soundex_b) ? 'Yes' : 'No';
            $metaphone_a  = metaphone($text);
            $metaphone_b  = metaphone($text2);
            $meta_match   = ($metaphone_a === $metaphone_b) ? 'Yes' : 'No';

            $out  = "=== String Similarity ===\n";
            $out .= "Text 1: \"{$text}\"\n";
            $out .= "Text 2: \"{$text2}\"\n";
            $out .= str_repeat('-', 40) . "\n";
            $out .= "Similarity:       " . round($percent, 1) . "%\n";
            $out .= "Levenshtein dist: {$lev} edit(s)\n";
            $out .= "Soundex match:    {$soundex_match}  ({$soundex_a} / {$soundex_b})\n";
            $out .= "Metaphone match:  {$meta_match}  ({$metaphone_a} / {$metaphone_b})\n";

            return tool_success($out);
        }

        // --- Encode/decode text ---
        case 'encode': {
            if (empty($text)) return tool_error('text_stats encode: "text" is required.');

            $out = "Input:    \"{$text}\"\n";
            $out .= "Encoding: {$encode}\n";
            $out .= str_repeat('-', 40) . "\n";

            switch ($encode) {
                case 'base64':
                    $out .= "Result: " . base64_encode($text); break;
                case 'base64decode':
                    $decoded = base64_decode($text, strict: true);
                    $out .= "Result: " . ($decoded !== false ? $decoded : '(invalid base64 input)'); break;
                case 'url':
                    $out .= "Result: " . urlencode($text); break;
                case 'urldecode':
                    $out .= "Result: " . urldecode($text); break;
                case 'html':
                    $out .= "Result: " . htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'); break;
                case 'htmldecode':
                    $out .= "Result: " . htmlspecialchars_decode($text, ENT_QUOTES | ENT_HTML5); break;
                case 'hex':
                    $out .= "Result: " . bin2hex($text); break;
                case 'hexdecode':
                    $decoded = @hex2bin($text);
                    $out .= "Result: " . ($decoded !== false ? $decoded : '(invalid hex input)'); break;
                case 'rot13':
                    $out .= "Result: " . str_rot13($text); break;
                default:
                    return tool_error("Unknown encoding: '{$encode}'. Valid: base64, base64decode, url, urldecode, html, htmldecode, hex, hexdecode, rot13");
            }
            return tool_success($out);
        }

        // --- Hash a string ---
        case 'hash': {
            if (empty($text)) return tool_error('text_stats hash: "text" is required.');

            $available = hash_algos();
            if (!in_array($algo, $available, true)) {
                $common = array_intersect(['md5','sha1','sha256','sha512','sha3-256'], $available);
                return tool_error("Unknown hash algorithm '{$algo}'. Common options: " . implode(', ', $common));
            }

            $result   = hash($algo, $text);
            $hmac     = ''; // Not included unless a key is provided

            $out  = "Input:     \"{$text}\"\n";
            $out .= "Algorithm: {$algo}\n";
            $out .= "Hash:      {$result}\n";
            $out .= "Length:    " . strlen($result) . " hex chars (" . (strlen($result) * 4) . " bits)";

            return tool_success($out);
        }

        // --- Word frequency count only ---
        case 'wordcount': {
            if (empty($text)) return tool_error('text_stats wordcount: "text" is required.');
            $words = str_word_count($text);
            $chars = mb_strlen($text);
            $lines = substr_count($text, "\n") + 1;
            $out   = "Words:      {$words}\n";
            $out  .= "Characters: {$chars}\n";
            $out  .= "Lines:      {$lines}";
            return tool_success($out);
        }

        default:
            return tool_error(
                "Unknown text_stats action: '{$action}'. " .
                "Valid actions: stats, similarity, encode, hash, wordcount"
            );
    }
}


// ---------------------------------------------------------------------------
// text_stats Helpers
// ---------------------------------------------------------------------------

/** Very rough syllable count approximation for Flesch score */
function count_syllables_approx(string $text): int {
    $words = str_word_count(strtolower($text), 1);
    $total = 0;
    foreach ($words as $word) {
        // Count vowel groups as syllables, min 1 per word
        $count  = preg_match_all('/[aeiouy]+/i', $word);
        $total += max(1, $count);
    }
    return $total;
}

/** Returns a human-readable label for a Flesch Reading Ease score */
function flesch_label(mixed $score): string {
    if (!is_numeric($score)) return 'N/A';
    return match (true) {
        $score >= 90 => 'Very Easy (5th grade)',
        $score >= 80 => 'Easy (6th grade)',
        $score >= 70 => 'Fairly Easy (7th grade)',
        $score >= 60 => 'Standard (8th–9th grade)',
        $score >= 50 => 'Fairly Difficult (10th–12th grade)',
        $score >= 30 => 'Difficult (College level)',
        default      => 'Very Difficult (Professional)',
    };
}

/** Returns the top N most frequent non-trivial words from text */
function top_words(string $text, int $n = 10): array {
    static $stop_words = [
        'the','a','an','and','or','but','in','on','at','to','for',
        'of','with','by','from','is','was','are','were','be','been',
        'have','has','had','do','does','did','will','would','could',
        'should','may','might','that','this','it','its','i','you',
        'he','she','we','they','not','as','so','if','can','than',
        'then','when','there','their','what','which','who','how',
    ];

    $words = str_word_count(strtolower($text), 1);
    $freq  = [];
    foreach ($words as $w) {
        if (strlen($w) > 2 && !in_array($w, $stop_words, true)) {
            $freq[$w] = ($freq[$w] ?? 0) + 1;
        }
    }
    arsort($freq);
    return array_slice($freq, 0, $n, preserve_keys: true);
}
