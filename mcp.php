<?php
/**
 * MCP Server - Web Fetch Tool
 * Implements the Model Context Protocol over HTTP + SSE transport.
 * Compatible with LM Studio and other MCP clients.
 *
 * Endpoints:
 *   GET  /mcp.php        → SSE stream (MCP handshake + event loop)
 *   POST /mcp.php        → Receives tool call JSON-RPC messages
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/fetcher.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- Route ---
if ($method === 'GET') {
    handle_sse();
} elseif ($method === 'POST') {
    handle_post();
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}


// ---------------------------------------------------------------------------
// SSE HANDLER
// Keeps a persistent connection open. Sends the MCP initialize handshake,
// then advertises available tools, then waits for the client to POST calls.
// ---------------------------------------------------------------------------
function handle_sse(): void {
    // Auth check
    if (!verify_auth()) {
        http_response_code(401);
        echo "Unauthorized";
        exit;
    }

    // Required SSE headers
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no'); // Disable nginx buffering if present
    header('Access-Control-Allow-Origin: *');

    // Disable output buffering so events stream immediately
    if (ob_get_level()) ob_end_clean();
    ini_set('output_buffering', 'off');
    ini_set('zlib.output_compression', false);

    // Set execution time limit (adjust based on your host's max)
    set_time_limit(MCP_SSE_TIMEOUT);

    // --- MCP: Send server capabilities ---
    sse_send('endpoint', json_encode([
        'protocolVersion' => '2024-11-05',
        'capabilities'    => [
            'tools' => ['listChanged' => false],
        ],
        'serverInfo' => [
            'name'    => 'php-webfetch-mcp',
            'version' => '1.0.0',
        ],
    ]));

    // --- MCP: Advertise available tools ---
    sse_send('tools/list', json_encode([
        'tools' => get_tool_definitions(),
    ]));

    // Keep the connection alive with periodic heartbeats
    // LM Studio will POST tool calls separately; we just need to stay open.
    $start = time();
    while (true) {
        // Send a comment ping every 15 seconds to keep the connection alive
        echo ": ping\n\n";
        flush();

        if (connection_aborted()) {
            break;
        }

        // Respect the configured timeout
        if ((time() - $start) >= MCP_SSE_TIMEOUT) {
            break;
        }

        sleep(15);
    }
}


// ---------------------------------------------------------------------------
// POST HANDLER
// Receives JSON-RPC 2.0 messages from LM Studio when the model calls a tool.
// ---------------------------------------------------------------------------
function handle_post(): void {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

    // Handle CORS preflight
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    // Auth check
    if (!verify_auth()) {
        http_response_code(401);
        echo json_encode(jsonrpc_error(null, -32001, 'Unauthorized'));
        exit;
    }

    // Parse incoming JSON-RPC body
    $raw = file_get_contents('php://input');
    $msg = json_decode($raw, true);

    if (!$msg || !isset($msg['method'])) {
        echo json_encode(jsonrpc_error($msg['id'] ?? null, -32600, 'Invalid request'));
        exit;
    }

    $id     = $msg['id']     ?? null;
    $method = $msg['method'] ?? '';
    $params = $msg['params'] ?? [];

    // --- Route JSON-RPC methods ---
    switch ($method) {

        // MCP initialize handshake (some clients POST this instead of SSE)
        case 'initialize':
            echo json_encode(jsonrpc_result($id, [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => ['tools' => ['listChanged' => false]],
                'serverInfo'      => ['name' => 'php-webfetch-mcp', 'version' => '1.0.0'],
            ]));
            break;

        // Tool list request
        case 'tools/list':
            echo json_encode(jsonrpc_result($id, [
                'tools' => get_tool_definitions(),
            ]));
            break;

        // Tool call — the LLM wants to use a tool
        case 'tools/call':
            $tool_name = $params['name']      ?? '';
            $arguments = $params['arguments'] ?? [];
            $result    = dispatch_tool($tool_name, $arguments);
            echo json_encode(jsonrpc_result($id, $result));
            break;

        default:
            echo json_encode(jsonrpc_error($id, -32601, "Method not found: $method"));
            break;
    }
}


// ---------------------------------------------------------------------------
// HELPERS
// ---------------------------------------------------------------------------

/** Send a Server-Sent Event */
function sse_send(string $event, string $data): void {
    echo "event: {$event}\n";
    echo "data: {$data}\n\n";
    flush();
}

/** Build a JSON-RPC 2.0 success response */
function jsonrpc_result(mixed $id, mixed $result): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

/** Build a JSON-RPC 2.0 error response */
function jsonrpc_error(mixed $id, int $code, string $message): array {
    return [
        'jsonrpc' => '2.0',
        'id'      => $id,
        'error'   => ['code' => $code, 'message' => $message],
    ];
}
