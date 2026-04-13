# PHP MCP Web Tools

**A Model Context Protocol server for local LLMs — runs on shared hosting, zero dependencies.**

Give your local LLM real tool access without running a second process, opening firewall ports, or touching Node.js or Python. Upload a handful of PHP files to any standard web host and you're done.

<img width="723" height="68" alt="image" src="https://github.com/user-attachments/assets/0d2261a0-e0ac-4178-9b9c-f225c5c57e63" />
<img width="928" height="339" alt="image" src="https://github.com/user-attachments/assets/6d5beb12-3986-49f5-bfe6-9d165a8eee26" />
<img width="1918" height="907" alt="image" src="https://github.com/user-attachments/assets/8782bd4f-9561-4731-b61b-9389fad81c49" />

---

## Why This Exists

Most MCP server examples assume you have somewhere to run a persistent process — a spare machine, a VPS, a Docker container. If you're running a local LLM at home (via [LM Studio](https://lmstudio.ai), [Ollama](https://ollama.ai), or similar), you probably don't want that overhead.

This project takes a different approach: **a plain PHP script on shared hosting acts as your MCP server**. No runtime to install, no ports to open, no process to keep alive. If you have web hosting (the kind that costs $5–10/month), you already have everything you need.

It also includes a ready-to-use **web chat client** pre-wired for MCP tool support, so you can go from zero to a working AI assistant with tool access in under 10 minutes.

---

## What's Included

```
php-mcp-web-tools/
├── server/                  ← Upload this to your web host
│   ├── mcp.php              ← MCP endpoint (the URL you point clients at)
│   ├── config.php           ← Your settings: API key, allowed domains, limits
│   ├── auth.php             ← Shared secret authentication
│   ├── tools.php            ← Tool registry and dispatcher
│   ├── tools_extended.php   ← Extended tool implementations
│   ├── fetcher.php          ← HTTP fetching and HTML extraction
│   └── .htaccess            ← Security headers and access rules
│
└── client/                  ← Open locally in any browser
    ├── index.html
    ├── app.js
    └── styles.css
```

---

## Tools Available

Once configured, your LLM has access to five tools out of the box:

### 🌐 Web Tools
| Tool | What it does |
|---|---|
| `fetch_webpage` | Fetches a URL and returns clean, readable text extracted from the page |
| `fetch_links` | Fetches a URL and returns all hyperlinks found on the page |

### 🕐 Date & Time
| Tool | What it does |
|---|---|
| `datetime_tool` | Current time in any timezone, timezone conversion, date arithmetic, date formatting |

### 🔍 DNS & Network
| Tool | What it does |
|---|---|
| `dns_lookup` | DNS record queries (A, MX, TXT, NS), HTTP header inspection, hostname resolution, URL status checks |

### 📝 Text Processing
| Tool | What it does |
|---|---|
| `text_stats` | Word/character counts, readability scores, string similarity, hashing (SHA-256 etc.), encoding/decoding (base64, URL, hex) |

---

## Quick Start

### 1. Set up the server

**Upload** the `server/` folder to your web host. A subfolder works well:

```
public_html/mcp/
```

This makes the server available at: `https://yoursite.com/mcp/mcp.php`

**Edit `config.php`** — set a strong API key:

```php
define('MCP_API_KEY', 'REPLACE_WITH_YOUR_SECRET_KEY');
```

Generate one with: `php -r "echo bin2hex(random_bytes(32));"`

**Verify your host has** PHP 8.0+, cURL, and DOMDocument enabled (standard on virtually all shared hosts).

**Test it** from your terminal:

```bash
curl -X POST https://yoursite.com/mcp/mcp.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your-secret-key" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
```

You should see a JSON response listing all five tools.

---

### 2. Connect LM Studio

This server speaks **HTTP + SSE transport**, which LM Studio supports for remote MCP servers.

**Via the LM Studio UI:**

Go to Settings → MCP Servers → Add Server and fill in:

| Field | Value |
|---|---|
| Name | Web Tools |
| Type | HTTP / Remote |
| URL | `https://yoursite.com/mcp/mcp.php` |
| API Key Header | `X-API-Key` |
| API Key Value | *(your key from config.php)* |

**Via `mcp.json`** (recommended for permanent setup):

```json
{
  "mcpServers": {
    "webtools": {
      "type": "http",
      "url": "https://yoursite.com/mcp/mcp.php",
      "headers": {
        "X-API-Key": "your-secret-key"
      }
    }
  }
}
```

**Via the LM Studio API** (ephemeral, per-request):

```json
{
  "model": "your-model",
  "input": "What does the homepage of example.com say?",
  "integrations": [
    {
      "type": "ephemeral_mcp",
      "server_label": "webtools",
      "server_url": "https://yoursite.com/mcp/mcp.php",
      "headers": { "X-API-Key": "your-secret-key" }
    }
  ]
}
```

---

### 3. Use the included chat client

The `client/` folder is a standalone web chat client that works with any OpenAI-compatible local LLM and has MCP tool support built in.

**Just open `client/index.html` in your browser** — no server, no build step, no npm install.

First time setup (click **Settings**):

1. Set **Base URL** to your LM Studio server (default: `http://localhost:1234/v1`)
2. Set your **Model** name
3. Scroll to **Tools (MCP Server)**
4. Paste your MCP server URL and API key
5. Click **Test & Load Tools** — you should see all five tools listed
6. Check **Enable MCP tool server** → Save

Once enabled, a purple `⚙ 5 tool(s) active` indicator appears in the composer. Your model will automatically use tools when it decides they'd help answer a question.

**Try it:**
- *"What's on the homepage of news.ycombinator.com?"*
- *"What time is it right now in Tokyo?"*
- *"Look up the MX records for gmail.com"*
- *"What's the SHA-256 hash of 'hello world'?"*
- *"How many days until Christmas?"*

---

## Configuration Reference

All settings are in `server/config.php`:

```php
// Authentication
define('MCP_API_KEY', 'your-key-here');
define('MCP_AUTH_ENABLED', true);

// Domain allowlist — restrict which URLs the LLM can fetch.
// Leave as [] to allow all public URLs.
define('MCP_ALLOWED_DOMAINS', [
    // 'wikipedia.org',
    // 'github.com',
]);

// How much page text to return per fetch (characters)
define('MCP_MAX_TEXT_LENGTH', 8000);   // ~2000 tokens

// cURL timeout per request (seconds)
define('MCP_FETCH_TIMEOUT', 15);

// SSE connection keepalive (seconds)
define('MCP_SSE_TIMEOUT', 55);
```

**Domain allowlist tip:** Start with it empty (allow all) to test, then lock it down to only the sites you actually want the LLM to access. This also prevents the model from using your server to probe unexpected URLs.

---

## Compatibility

**MCP server** — works with any client that supports MCP over HTTP+SSE transport, including:
- LM Studio (1.0+)
- Any custom client using the MCP spec

**Chat client** — works with any OpenAI-compatible API endpoint, including:
- LM Studio
- Ollama (with OpenAI compatibility mode)
- LocalAI
- OpenAI, Anthropic (via compatible proxy), and others

**Tool use in the chat client** requires a model that supports function calling. Models known to work well:
- Mistral / Mixtral instruct variants
- Llama 3.1+ (8B and above)
- Qwen 2.5 instruct
- IBM Granite 3+
- Most models tagged `tool_use` or `function_calling` on Hugging Face

---

## Security Notes

- **Always use HTTPS** — your API key travels in request headers
- **Set a strong, random API key** — the endpoint is publicly reachable
- **Use `MCP_ALLOWED_DOMAINS`** — limits what the LLM can ask your server to fetch
- **SSRF protection is built in** — private IPs, localhost, and cloud metadata endpoints are always blocked regardless of allowlist settings
- **The `.htaccess`** blocks direct browser access to all include files

---

## Adding Your Own Tools

The tool system is designed to be extended. In `tools.php`:

1. Add a definition to `get_tool_definitions()`
2. Add a `case` to the `match` in `dispatch_tool()`
3. Write the handler — return `tool_success($text)` or `tool_error($message)`

```php
// Example: a simple tool that echoes input back
function tool_my_tool(array $args): array {
    $input = $args['input'] ?? '';
    if (empty($input)) return tool_error('"input" is required.');
    return tool_success("You said: {$input}");
}
```

PHP's standard library has a lot to offer here — see `tools_extended.php` for patterns to follow.

---

## Server Requirements

| Requirement | Notes |
|---|---|
| PHP 8.0+ | Uses `match`, `str_starts_with`, `str_ends_with`, named args |
| cURL extension | For fetching URLs — enabled by default on virtually all shared hosts |
| DOMDocument extension | For HTML parsing — part of PHP's standard `dom` extension |
| HTTPS | Strongly recommended; free via Let's Encrypt on most hosts |
| mod_rewrite / .htaccess | For security headers — optional but recommended |

---

## License

MIT — use it, fork it, build on it.

---

*Built to scratch a specific itch: giving a locally-hosted LLM useful tools without any infrastructure overhead beyond a shared hosting plan that was already paid for.*
