// =============================================================================
// CONSTANTS & DEFAULTS
// =============================================================================

const STORAGE_KEYS = {
  settings: 'goose_gpt_settings',
  chats: 'goose_gpt_chats',
  activeChatId: 'goose_gpt_active_chat_id'
};

const DEFAULT_SETTINGS = {
  baseUrl: 'http://localhost:1234/v1',
  apiKey: 'lm-studio',
  model: '',
  temperature: 0.7,
  maxTokens: '',
  systemPrompt: 'You are a helpful assistant.',
  stream: true,
  mcpUrl: '',
  mcpApiKey: '',
  mcpEnabled: false,
  mcpTools: []
};

const MAX_TOOL_ROUNDS = 6;

// =============================================================================
// STATE
// =============================================================================

const state = {
  settings: loadSettings(),
  chats: loadChats(),
  activeChatId: localStorage.getItem(STORAGE_KEYS.activeChatId) || null,
  pendingAttachments: [],
  isSending: false,
  abortController: null,
  pdfReady: false
};

// =============================================================================
// DOM REFS
// =============================================================================

const els = {
  chatList: document.getElementById('chatList'),
  messages: document.getElementById('messages'),
  emptyState: document.getElementById('emptyState'),
  promptInput: document.getElementById('promptInput'),
  sendBtn: document.getElementById('sendBtn'),
  newChatBtn: document.getElementById('newChatBtn'),
  clearChatsBtn: document.getElementById('clearChatsBtn'),
  settingsBtn: document.getElementById('settingsBtn'),
  closeSettingsBtn: document.getElementById('closeSettingsBtn'),
  saveSettingsBtn: document.getElementById('saveSettingsBtn'),
  resetSettingsBtn: document.getElementById('resetSettingsBtn'),
  settingsModal: document.getElementById('settingsModal'),
  attachBtn: document.getElementById('attachBtn'),
  fileInput: document.getElementById('fileInput'),
  attachmentBar: document.getElementById('attachmentBar'),
  statusPill: document.getElementById('statusPill'),
  baseUrlInput: document.getElementById('baseUrlInput'),
  apiKeyInput: document.getElementById('apiKeyInput'),
  modelInput: document.getElementById('modelInput'),
  temperatureInput: document.getElementById('temperatureInput'),
  maxTokensInput: document.getElementById('maxTokensInput'),
  systemPromptInput: document.getElementById('systemPromptInput'),
  streamInput: document.getElementById('streamInput'),
  mcpUrlInput: document.getElementById('mcpUrlInput'),
  mcpApiKeyInput: document.getElementById('mcpApiKeyInput'),
  mcpEnabledInput: document.getElementById('mcpEnabledInput'),
  mcpTestBtn: document.getElementById('mcpTestBtn'),
  mcpStatus: document.getElementById('mcpStatus'),
  sidebar: document.getElementById('sidebar'),
  openSidebarBtn: document.getElementById('openSidebarBtn'),
  toggleSidebarBtn: document.getElementById('toggleSidebarBtn'),
  toolsIndicator: document.getElementById('toolsIndicator')
};

// =============================================================================
// INIT
// =============================================================================

init();

function init() {
  initializeLibraries();
  if (!state.chats.length) {
    const chat = createNewChat(false);
    state.activeChatId = chat.id;
  }
  if (!getActiveChat()) {
    state.activeChatId = state.chats[0]?.id || null;
  }
  hydrateSettingsForm();
  bindEvents();
  renderChatList();
  renderActiveChat();
  autoResizeTextarea();
  updateToolsIndicator();
}

// =============================================================================
// EVENTS
// =============================================================================

function bindEvents() {
  els.sendBtn.addEventListener('click', handleSend);
  els.newChatBtn.addEventListener('click', () => { createNewChat(true); closeSidebar(); });

  els.clearChatsBtn.addEventListener('click', () => {
    if (!confirm('Delete all saved chats?')) return;
    state.chats = [];
    const chat = createNewChat(false);
    state.activeChatId = chat.id;
    persistChats();
    renderChatList();
    renderActiveChat();
  });

  els.promptInput.addEventListener('input', autoResizeTextarea);
  els.promptInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); handleSend(); }
  });

  els.settingsBtn.addEventListener('click', openSettings);
  els.closeSettingsBtn.addEventListener('click', closeSettings);
  els.saveSettingsBtn.addEventListener('click', saveSettingsFromForm);
  els.resetSettingsBtn.addEventListener('click', () => {
    state.settings = { ...DEFAULT_SETTINGS };
    persistSettings();
    hydrateSettingsForm();
    updateToolsIndicator();
    setStatus('Defaults restored');
  });

  els.settingsModal.addEventListener('click', (e) => {
    if (e.target === els.settingsModal) closeSettings();
  });

  els.attachBtn.addEventListener('click', () => els.fileInput.click());
  els.fileInput.addEventListener('change', async (e) => {
    const files = Array.from(e.target.files || []);
    await addAttachments(files);
    els.fileInput.value = '';
  });

  document.querySelectorAll('[data-prompt]').forEach((btn) => {
    btn.addEventListener('click', () => {
      els.promptInput.value = btn.dataset.prompt || '';
      autoResizeTextarea();
      els.promptInput.focus();
    });
  });

  els.messages.addEventListener('click', async (e) => {
    const codeBtn = e.target.closest('[data-copy-code]');
    if (codeBtn) {
      const code = codeBtn.closest('.code-block')?.querySelector('code')?.innerText || '';
      await copyTextWithFeedback(codeBtn, code);
      return;
    }
    const msgBtn = e.target.closest('[data-copy-message]');
    if (msgBtn) {
      const article = msgBtn.closest('.message');
      await copyTextWithFeedback(msgBtn, article?.dataset.copyText || '');
    }
    const toggleBtn = e.target.closest('[data-tool-toggle]');
    if (toggleBtn) {
      const resultEl = toggleBtn.closest('.tool-call-card')?.querySelector('.tool-result-body');
      if (resultEl) {
        const isHidden = resultEl.classList.toggle('hidden');
        toggleBtn.textContent = isHidden ? 'Show result' : 'Hide result';
      }
    }
  });

  els.openSidebarBtn.addEventListener('click', () => els.sidebar.classList.add('open'));
  els.toggleSidebarBtn.addEventListener('click', closeSidebar);

  els.mcpTestBtn.addEventListener('click', async () => {
    const url = els.mcpUrlInput.value.trim();
    const key = els.mcpApiKeyInput.value.trim();
    if (!url) { setMcpStatus('Enter a server URL first.', 'error'); return; }
    setMcpStatus('Connecting…', 'pending');
    const tools = await fetchMcpTools(url, key);
    if (tools) {
      els.mcpUrlInput.dataset.cachedTools = JSON.stringify(tools);
      setMcpStatus(`Connected — ${tools.length} tool(s): ${tools.map(t => t.name).join(', ')}`, 'ok');
    } else {
      setMcpStatus('Could not reach MCP server. Check URL and API key.', 'error');
    }
  });

  enableDragAndDrop();
}

// =============================================================================
// PERSISTENCE
// =============================================================================

function loadSettings() {
  try { return { ...DEFAULT_SETTINGS, ...(JSON.parse(localStorage.getItem(STORAGE_KEYS.settings)) || {}) }; }
  catch { return { ...DEFAULT_SETTINGS }; }
}

function loadChats() {
  try { return JSON.parse(localStorage.getItem(STORAGE_KEYS.chats)) || []; }
  catch { return []; }
}

function persistSettings() {
  localStorage.setItem(STORAGE_KEYS.settings, JSON.stringify(state.settings));
}

function persistChats() {
  localStorage.setItem(STORAGE_KEYS.chats, JSON.stringify(state.chats));
  localStorage.setItem(STORAGE_KEYS.activeChatId, state.activeChatId || '');
}

// =============================================================================
// CHAT MANAGEMENT
// =============================================================================

function createNewChat(shouldRender = true) {
  const chat = { id: crypto.randomUUID(), title: 'New chat', createdAt: Date.now(), updatedAt: Date.now(), messages: [] };
  state.chats.unshift(chat);
  state.activeChatId = chat.id;
  persistChats();
  if (shouldRender) { renderChatList(); renderActiveChat(); els.promptInput.focus(); }
  return chat;
}

function getActiveChat() {
  return state.chats.find(c => c.id === state.activeChatId) || null;
}

// =============================================================================
// RENDER: CHAT LIST
// =============================================================================

function renderChatList() {
  els.chatList.innerHTML = '';
  state.chats.sort((a, b) => b.updatedAt - a.updatedAt).forEach((chat) => {
    const btn = document.createElement('button');
    btn.className = `chat-item ${chat.id === state.activeChatId ? 'active' : ''}`;
    btn.innerHTML = `
      <div class="chat-item-title">${escapeHtml(chat.title || 'Untitled')}</div>
      <div class="chat-item-meta">${formatDate(chat.updatedAt)} · ${chat.messages.length} messages</div>
    `;
    btn.addEventListener('click', () => {
      state.activeChatId = chat.id;
      persistChats();
      renderChatList();
      renderActiveChat();
      closeSidebar();
    });
    els.chatList.appendChild(btn);
  });
}

// =============================================================================
// RENDER: MESSAGES
// =============================================================================

function renderActiveChat() {
  const chat = getActiveChat();
  els.messages.innerHTML = '';
  if (!chat || !chat.messages.length) {
    els.emptyState.classList.remove('hidden');
    renderAttachmentBar();
    return;
  }
  els.emptyState.classList.add('hidden');
  chat.messages.forEach(renderMessage);
  renderAttachmentBar();
  requestAnimationFrame(scrollMessagesToBottom);
}

function renderMessage(message) {
  if (message.role === 'tool_trace') { renderToolTrace(message); return; }
  if (message.role === 'tool') return;
  if (message.role === 'assistant' && message.tool_calls?.length && !message.content) return;

  const template = document.getElementById('messageTemplate');
  const node     = template.content.firstElementChild.cloneNode(true);
  const avatar   = node.querySelector('.avatar');
  const meta     = node.querySelector('.message-meta');
  const content  = node.querySelector('.message-content');
  const copyBtn  = node.querySelector('[data-copy-message]');
  const plainText = message.display || extractPlainText(message.content);

  node.classList.add(message.role);
  node.dataset.copyText  = plainText || '';
  avatar.textContent     = message.role === 'user' ? 'U' : message.role === 'assistant' ? 'AI' : 'S';
  meta.textContent       = getRoleLabel(message.role);

  if (message.role === 'assistant' && !message.isTyping) copyBtn.classList.remove('hidden');

  if (message.isTyping) {
    content.innerHTML = `<div class="typing-dot-wrap"><span class="typing-dot"></span><span class="typing-dot"></span><span class="typing-dot"></span></div>`;
  } else {
    content.innerHTML = renderMarkdown(plainText);
    highlightCodeBlocks(content);
  }

  els.messages.appendChild(node);
}

function renderToolTrace(message) {
  const wrap = document.createElement('div');
  wrap.className = 'tool-trace-wrap';

  (message.calls || []).forEach((call) => {
    const card = document.createElement('div');
    card.className = `tool-call-card${call.isError ? ' tool-error' : ''}`;
    const argSummary = formatArgSummary(call.args);
    card.innerHTML = `
      <div class="tool-call-header">
        <span class="tool-icon">⚙</span>
        <span class="tool-name">${escapeHtml(call.name)}</span>
        ${argSummary ? `<span class="tool-args-summary">${escapeHtml(argSummary)}</span>` : ''}
        <button class="ghost-btn tool-toggle-btn" data-tool-toggle>${call.isError ? 'Show error' : 'Show result'}</button>
      </div>
      <div class="tool-result-body hidden">
        <pre class="tool-result-text">${escapeHtml(call.result || '')}</pre>
      </div>
    `;
    wrap.appendChild(card);
  });

  els.messages.appendChild(wrap);
}

function formatArgSummary(args) {
  if (!args || typeof args !== 'object') return '';
  return Object.entries(args).slice(0, 2)
    .map(([k, v]) => `${k}: ${String(v).slice(0, 40)}${String(v).length > 40 ? '…' : ''}`)
    .join(' · ');
}

function updateLastAssistantMessage(displayText) {
  const nodes   = els.messages.querySelectorAll('.message.assistant');
  const lastMsg = nodes[nodes.length - 1];
  const lastNode = lastMsg?.querySelector('.message-content');
  if (!lastNode) return;
  if (lastMsg) lastMsg.dataset.copyText = displayText || '';
  lastNode.innerHTML = renderMarkdown(displayText || '');
  highlightCodeBlocks(lastNode);
  scrollMessagesToBottom();
}

function getRoleLabel(role) {
  if (role === 'assistant') return 'Assistant';
  if (role === 'user') return 'You';
  return 'System';
}

// =============================================================================
// SETTINGS
// =============================================================================

function hydrateSettingsForm() {
  els.baseUrlInput.value       = state.settings.baseUrl;
  els.apiKeyInput.value        = state.settings.apiKey;
  els.modelInput.value         = state.settings.model;
  els.temperatureInput.value   = state.settings.temperature;
  els.maxTokensInput.value     = state.settings.maxTokens;
  els.systemPromptInput.value  = state.settings.systemPrompt;
  els.streamInput.checked      = !!state.settings.stream;
  els.mcpUrlInput.value        = state.settings.mcpUrl;
  els.mcpApiKeyInput.value     = state.settings.mcpApiKey;
  els.mcpEnabledInput.checked  = !!state.settings.mcpEnabled;

  const count = state.settings.mcpTools?.length || 0;
  if (count && state.settings.mcpUrl) {
    setMcpStatus(`${count} tool(s) loaded: ${state.settings.mcpTools.map(t => t.name).join(', ')}`, 'ok');
  } else {
    setMcpStatus('', '');
  }
}

function saveSettingsFromForm() {
  let mcpTools = state.settings.mcpTools || [];
  const cachedTools = els.mcpUrlInput.dataset.cachedTools;
  if (cachedTools) {
    try { mcpTools = JSON.parse(cachedTools); } catch {}
    delete els.mcpUrlInput.dataset.cachedTools;
  }
  const newUrl = els.mcpUrlInput.value.trim();
  if (newUrl !== state.settings.mcpUrl && !cachedTools) mcpTools = [];

  state.settings = {
    baseUrl:      els.baseUrlInput.value.trim()      || DEFAULT_SETTINGS.baseUrl,
    apiKey:       els.apiKeyInput.value.trim(),
    model:        els.modelInput.value.trim()         || DEFAULT_SETTINGS.model,
    temperature:  Number.isFinite(Number(els.temperatureInput.value)) ? Number(els.temperatureInput.value) : DEFAULT_SETTINGS.temperature,
    maxTokens:    els.maxTokensInput.value.trim(),
    systemPrompt: els.systemPromptInput.value.trim()  || DEFAULT_SETTINGS.systemPrompt,
    stream:       els.streamInput.checked,
    mcpUrl:       newUrl,
    mcpApiKey:    els.mcpApiKeyInput.value.trim(),
    mcpEnabled:   els.mcpEnabledInput.checked,
    mcpTools
  };

  persistSettings();
  closeSettings();
  updateToolsIndicator();
  setStatus('Settings saved');
}

function setMcpStatus(text, type) {
  els.mcpStatus.textContent = text;
  els.mcpStatus.className   = 'mcp-status';
  if (type) els.mcpStatus.classList.add(`mcp-status-${type}`);
}

function updateToolsIndicator() {
  const active = state.settings.mcpEnabled && state.settings.mcpTools?.length > 0;
  els.toolsIndicator.classList.toggle('hidden', !active);
  if (active) els.toolsIndicator.textContent = `⚙ ${state.settings.mcpTools.length} tool(s) active`;
}

function openSettings()  { hydrateSettingsForm(); els.settingsModal.classList.remove('hidden'); }
function closeSettings() { els.settingsModal.classList.add('hidden'); }
function setStatus(text) { els.statusPill.textContent = text; }

function autoResizeTextarea() {
  els.promptInput.style.height = 'auto';
  els.promptInput.style.height = `${Math.min(els.promptInput.scrollHeight, 220)}px`;
}

// =============================================================================
// MCP
// =============================================================================

async function fetchMcpTools(url, apiKey) {
  try {
    const resp = await fetch(url, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', ...(apiKey ? { 'X-API-Key': apiKey } : {}) },
      body:    JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/list', params: {} }),
      signal:  AbortSignal.timeout(10000)
    });
    if (!resp.ok) return null;
    const json = await resp.json();
    return json?.result?.tools || null;
  } catch { return null; }
}

async function callMcpTool(toolName, args) {
  const url    = state.settings.mcpUrl;
  const apiKey = state.settings.mcpApiKey;
  try {
    const resp = await fetch(url, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', ...(apiKey ? { 'X-API-Key': apiKey } : {}) },
      body:    JSON.stringify({ jsonrpc: '2.0', id: crypto.randomUUID(), method: 'tools/call', params: { name: toolName, arguments: args } }),
      signal:  AbortSignal.timeout(30000)
    });
    if (!resp.ok) return { result: `HTTP error ${resp.status} from MCP server`, isError: true };
    const json = await resp.json();
    if (json.error) return { result: json.error.message || 'MCP server error', isError: true };
    const mcpResult = json.result || {};
    const text = (mcpResult.content || []).filter(c => c.type === 'text').map(c => c.text).join('\n');
    return { result: text || '(empty result)', isError: !!mcpResult.isError };
  } catch (err) {
    return { result: `Tool call failed: ${err.message}`, isError: true };
  }
}

function buildToolsArray() {
  if (!state.settings.mcpEnabled || !state.settings.mcpTools?.length) return null;
  return state.settings.mcpTools.map(tool => ({
    type: 'function',
    function: { name: tool.name, description: tool.description || '', parameters: tool.inputSchema || { type: 'object', properties: {} } }
  }));
}

// =============================================================================
// ATTACHMENTS
// =============================================================================

async function addAttachments(files) {
  if (!files.length) return;
  const prepared = [];
  for (const file of files) prepared.push(await prepareAttachment(file));
  state.pendingAttachments.push(...prepared.filter(Boolean));
  renderAttachmentBar();
  setStatus(`${state.pendingAttachments.length} attachment(s) ready`);
}

async function prepareAttachment(file) {
  if (file.type.startsWith('image/')) {
    return { id: crypto.randomUUID(), name: file.name, size: file.size, type: file.type || 'image/*', mode: 'image', dataUrl: await readFileAsDataUrl(file) };
  }
  if (isPdfFile(file)) {
    try {
      const text = await extractPdfText(file);
      return { id: crypto.randomUUID(), name: file.name, size: file.size, type: file.type || 'application/pdf', mode: 'pdf', text: limitText(text || '[No extractable text found in PDF]', 30000) };
    } catch (error) {
      return { id: crypto.randomUUID(), name: file.name, size: file.size, type: file.type, mode: 'binary', dataUrl: await readFileAsDataUrl(file), note: `PDF failed: ${error.message}` };
    }
  }
  if (isTextLikeFile(file)) {
    return { id: crypto.randomUUID(), name: file.name, size: file.size, type: file.type || 'text/plain', mode: 'text', text: limitText(await readFileAsText(file), 20000) };
  }
  return { id: crypto.randomUUID(), name: file.name, size: file.size, type: file.type || 'application/octet-stream', mode: 'binary', dataUrl: await readFileAsDataUrl(file) };
}

function renderAttachmentBar() {
  const items = state.pendingAttachments;
  els.attachmentBar.innerHTML = '';
  els.attachmentBar.classList.toggle('hidden', !items.length);
  items.forEach((file) => {
    const chip = document.createElement('div');
    chip.className = 'attachment-chip';
    chip.innerHTML = `<span>${escapeHtml(file.name)} · ${formatBytes(file.size)}</span><button title="Remove">✕</button>`;
    chip.querySelector('button').addEventListener('click', () => {
      state.pendingAttachments = state.pendingAttachments.filter(i => i.id !== file.id);
      renderAttachmentBar();
    });
    els.attachmentBar.appendChild(chip);
  });
}

// =============================================================================
// SEND + AGENTIC TOOL LOOP
// =============================================================================

async function handleSend() {
  const prompt      = els.promptInput.value.trim();
  const attachments = [...state.pendingAttachments];

  if (state.isSending) { state.abortController?.abort(); return; }
  if (!prompt && !attachments.length) return;

  const chat        = getActiveChat() || createNewChat(false);
  const userMessage = createUserMessage(prompt, attachments);
  chat.messages.push(userMessage);
  chat.updatedAt = Date.now();
  if (chat.title === 'New chat') chat.title = deriveTitle(prompt, attachments);

  const assistantPlaceholder = { id: crypto.randomUUID(), role: 'assistant', content: '', display: '', createdAt: Date.now(), isTyping: true };
  chat.messages.push(assistantPlaceholder);
  persistChats();

  els.promptInput.value = '';
  state.pendingAttachments = [];
  autoResizeTextarea();
  renderChatList();
  renderActiveChat();

  state.isSending       = true;
  state.abortController = new AbortController();
  els.sendBtn.textContent = 'Stop';
  setStatus('Thinking…');

  try {
    let round = 0;
    while (round < MAX_TOOL_ROUNDS) {
      round++;
      const payload            = buildRequestPayload(chat.messages);
      const { content, toolCalls } = await requestCompletion(payload, assistantPlaceholder);

      if (!toolCalls || toolCalls.length === 0) {
        assistantPlaceholder.isTyping = false;
        assistantPlaceholder.content  = content;
        assistantPlaceholder.display  = content;
        break;
      }

      // Remove typing placeholder, insert real assistant tool-call message
      const pidx = chat.messages.indexOf(assistantPlaceholder);
      if (pidx !== -1) chat.messages.splice(pidx, 1);

      chat.messages.push({ id: crypto.randomUUID(), role: 'assistant', content: content || null, tool_calls: toolCalls, display: '', createdAt: Date.now() });

      // Execute tools
      const traceCalls = [];
      setStatus(`Calling ${toolCalls.length} tool(s)…`);

      for (const tc of toolCalls) {
        const name = tc.function?.name || tc.name || '?';
        let   args = {};
        try { args = JSON.parse(tc.function?.arguments || '{}'); } catch {}
        setStatus(`⚙ ${name}…`);
        const { result, isError } = await callMcpTool(name, args);
        chat.messages.push({ id: crypto.randomUUID(), role: 'tool', tool_call_id: tc.id, content: result, toolName: name, toolArgs: args, isError, createdAt: Date.now() });
        traceCalls.push({ name, args, result, isError });
      }

      chat.messages.push({ id: crypto.randomUUID(), role: 'tool_trace', calls: traceCalls, createdAt: Date.now() });

      // Re-add typing placeholder for next round
      assistantPlaceholder.isTyping = true;
      assistantPlaceholder.display  = '';
      assistantPlaceholder.content  = '';
      chat.messages.push(assistantPlaceholder);

      persistChats();
      renderChatList();
      renderActiveChat();
      setStatus('Thinking…');
    }

    chat.updatedAt = Date.now();
    persistChats();
    renderChatList();
    renderActiveChat();
    setStatus('Ready');

  } catch (error) {
    if (error.name === 'AbortError') {
      setStatus('Stopped');
    } else {
      assistantPlaceholder.isTyping = false;
      assistantPlaceholder.content  = `Error: ${error.message}`;
      assistantPlaceholder.display  = `**Request failed**\n\n${error.message}`;
      setStatus('Request failed');
    }
    persistChats();
    renderActiveChat();
  } finally {
    state.isSending         = false;
    state.abortController   = null;
    els.sendBtn.textContent = 'Send';
  }
}

// =============================================================================
// MESSAGE & PAYLOAD BUILDERS
// =============================================================================

function createUserMessage(prompt, attachments) {
  let display = prompt || '';
  if (attachments.length) {
    const summary = attachments.map(f => `- ${f.name} (${f.mode})`).join('\n');
    display += `${display ? '\n\n' : ''}Attached files:\n${summary}`;
  }
  return { id: crypto.randomUUID(), role: 'user', content: buildUserContentParts(prompt, attachments), display, createdAt: Date.now() };
}

function buildUserContentParts(prompt, attachments) {
  const parts = [];
  if (prompt) parts.push({ type: 'text', text: prompt });
  attachments.forEach((file) => {
    if (file.mode === 'image') {
      parts.push({ type: 'image_url', image_url: { url: file.dataUrl } });
      parts.push({ type: 'text', text: `[Attached image: ${file.name}]` });
    } else if (file.mode === 'text' || file.mode === 'pdf') {
      parts.push({ type: 'text', text: `${file.mode === 'pdf' ? 'Attached PDF text' : 'Attached file'}: ${file.name}\n\n${file.text}\n\nEnd of attachment.` });
    } else {
      parts.push({ type: 'text', text: `Attached binary: ${file.name} (${file.type}, ${formatBytes(file.size)})${file.note ? `\n${file.note}` : ''}\n${limitText(file.dataUrl, 4000)}` });
    }
  });
  return parts.length === 1 && parts[0].type === 'text' ? parts[0].text : parts;
}

function buildRequestPayload(chatMessages) {
  const messages = [];
  if (state.settings.systemPrompt) messages.push({ role: 'system', content: state.settings.systemPrompt });

  chatMessages.filter(m => !m.isTyping && m.role !== 'tool_trace').forEach((m) => {
    if (m.role === 'tool') {
      messages.push({ role: 'tool', tool_call_id: m.tool_call_id, content: m.content || '' });
    } else if (m.role === 'assistant' && m.tool_calls?.length) {
      messages.push({ role: 'assistant', content: m.content || null, tool_calls: m.tool_calls });
    } else {
      messages.push({ role: m.role, content: m.content });
    }
  });

  const payload = { model: state.settings.model, messages, temperature: state.settings.temperature, stream: !!state.settings.stream };
  if (state.settings.maxTokens) payload.max_tokens = Number(state.settings.maxTokens);

  const tools = buildToolsArray();
  if (tools) { payload.tools = tools; payload.tool_choice = 'auto'; }

  return payload;
}

// =============================================================================
// API REQUESTS
// =============================================================================

async function requestCompletion(payload, assistantPlaceholder) {
  const endpoint = `${state.settings.baseUrl.replace(/\/$/, '')}/chat/completions`;
  const response = await fetch(endpoint, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${state.settings.apiKey}` },
    body:    JSON.stringify(payload),
    signal:  state.abortController.signal
  });

  if (!response.ok) throw new Error((await safeReadError(response)) || `HTTP ${response.status}`);

  if (payload.stream && response.body) return readStreamingResponse(response, assistantPlaceholder);

  const json      = await response.json();
  const message   = json.choices?.[0]?.message || {};
  return { content: message.content || '', toolCalls: message.tool_calls || [] };
}

async function readStreamingResponse(response, assistantPlaceholder) {
  const reader  = response.body.getReader();
  const decoder = new TextDecoder();
  let buffer    = '';
  let fullText  = '';
  const toolCallsAccum = {};

  while (true) {
    const { value, done } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });
    const lines = buffer.split('\n');
    buffer = lines.pop() || '';

    for (const rawLine of lines) {
      const line = rawLine.trim();
      if (!line.startsWith('data:')) continue;
      const data = line.slice(5).trim();
      if (!data || data === '[DONE]') continue;
      try {
        const json  = JSON.parse(data);
        const delta = json.choices?.[0]?.delta;
        if (!delta) continue;

        if (delta.content) {
          fullText += delta.content;
          assistantPlaceholder.display  = fullText;
          assistantPlaceholder.isTyping = false;
          updateLastAssistantMessage(fullText);
        }

        if (delta.tool_calls) {
          for (const tc of delta.tool_calls) {
            const idx = tc.index ?? 0;
            if (!toolCallsAccum[idx]) toolCallsAccum[idx] = { id: '', type: 'function', function: { name: '', arguments: '' } };
            if (tc.id)                  toolCallsAccum[idx].id                   = tc.id;
            if (tc.type)                toolCallsAccum[idx].type                 = tc.type;
            if (tc.function?.name)      toolCallsAccum[idx].function.name       += tc.function.name;
            if (tc.function?.arguments) toolCallsAccum[idx].function.arguments  += tc.function.arguments;
          }
        }
      } catch {}
    }
  }

  const toolCalls = Object.values(toolCallsAccum).filter(tc => tc.function.name);
  return { content: fullText || '', toolCalls };
}

// =============================================================================
// UTILITIES
// =============================================================================

function deriveTitle(prompt, attachments) {
  if (prompt) return prompt.slice(0, 42) + (prompt.length > 42 ? '…' : '');
  if (attachments.length) return `Files: ${attachments[0].name}`;
  return 'New chat';
}

function closeSidebar() { els.sidebar.classList.remove('open'); }

function enableDragAndDrop() {
  const area = document.body;
  ['dragenter', 'dragover'].forEach(ev => area.addEventListener(ev, e => { e.preventDefault(); setStatus('Drop files to attach'); }));
  ['dragleave', 'drop'].forEach(ev => area.addEventListener(ev, e => { e.preventDefault(); if (!state.isSending) setStatus('Ready'); }));
  area.addEventListener('drop', async e => { await addAttachments(Array.from(e.dataTransfer?.files || [])); });
}

function isTextLikeFile(file) {
  const type = (file.type || '').toLowerCase();
  const name = file.name.toLowerCase();
  const exts = ['.txt', '.md', '.js', '.ts', '.json', '.html', '.css', '.xml', '.csv', '.log', '.py', '.cs', '.sql', '.java', '.tsv'];
  return type.startsWith('text/') || exts.some(e => name.endsWith(e)) || ['application/json', 'application/xml'].includes(type);
}

function isPdfFile(file) {
  return (file.type || '').toLowerCase() === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
}

function readFileAsText(file) {
  return new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(String(r.result||'')); r.onerror = () => rej(new Error(`Failed to read ${file.name}`)); r.readAsText(file); });
}

function readFileAsDataUrl(file) {
  return new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(String(r.result||'')); r.onerror = () => rej(new Error(`Failed to read ${file.name}`)); r.readAsDataURL(file); });
}

function readFileAsArrayBuffer(file) {
  return new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(r.result); r.onerror = () => rej(new Error(`Failed to read ${file.name}`)); r.readAsArrayBuffer(file); });
}

async function extractPdfText(file) {
  if (!state.pdfReady || !window.pdfjsLib) throw new Error('PDF library not available.');
  const buffer = await readFileAsArrayBuffer(file);
  const pdf    = await window.pdfjsLib.getDocument({ data: buffer }).promise;
  const texts  = [];
  for (let i = 1; i <= pdf.numPages; i++) {
    const page    = await pdf.getPage(i);
    const content = await page.getTextContent();
    const text    = content.items.map(it => it.str || '').join(' ').trim();
    if (text) texts.push(`Page ${i}:\n${text}`);
  }
  return texts.join('\n\n');
}

function initializeLibraries() {
  if (window.pdfjsLib) {
    state.pdfReady = true;
    if (window.pdfjsLib.GlobalWorkerOptions) window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
  }
}

async function copyTextWithFeedback(button, text) {
  try {
    await navigator.clipboard.writeText(text || '');
    const orig = button.textContent;
    button.textContent = 'Copied';
    setTimeout(() => { button.textContent = orig; }, 1500);
  } catch { alert('Copy failed.'); }
}

function highlightCodeBlocks(container) {
  if (!container || !window.hljs) return;
  container.querySelectorAll('pre code').forEach(b => window.hljs.highlightElement(b));
}

function formatDate(ts) {
  return new Date(ts).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function formatBytes(bytes) {
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
  return `${(bytes / 1024 ** i).toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

function limitText(text, max) { return text.length > max ? `${text.slice(0, max)}\n\n[Truncated for size]` : text; }

function extractPlainText(content) {
  if (typeof content === 'string') return content;
  if (Array.isArray(content)) return content.map(i => i.text || i.image_url?.url || '').filter(Boolean).join('\n\n');
  return '';
}

function escapeHtml(value) {
  return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function normalizeLanguage(lang) {
  const v = String(lang || '').trim().toLowerCase();
  return { js: 'javascript', ts: 'typescript', py: 'python', sh: 'bash', shell: 'bash', csharp: 'cs', 'c++': 'cpp', 'c#': 'cs', yml: 'yaml', md: 'markdown' }[v] || v;
}

function renderMarkdown(markdown) {
  const source     = escapeHtml(markdown || '');
  const codeBlocks = [];

  let html = source.replace(/```([a-zA-Z0-9_+#.-]+)?\n([\s\S]*?)```/g, (_, lang = '', code = '') => {
    const index = codeBlocks.length;
    const nl    = normalizeLanguage(lang);
    codeBlocks.push(`<div class="code-block"><div class="code-block-header"><span>${lang || 'code'}</span><button class="ghost-btn" data-copy-code type="button">Copy</button></div><pre><code class="${nl ? `language-${nl}` : ''}">${code.trim()}</code></pre></div>`);
    return `@@CODEBLOCK_${index}@@`;
  });

  html = html
    .replace(/^&gt; (.*)$/gm, '<blockquote>$1</blockquote>')
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.*?)\*/g, '<em>$1</em>')
    .replace(/`([^`]+)`/g, '<span class="inline-code">$1</span>')
    .replace(/\[(.*?)\]\((https?:\/\/[^\s]+)\)/g, '<a href="$2" target="_blank" rel="noreferrer">$1</a>');

  html = html.split(/\n\n+/).map((block) => {
    const t = block.trim();
    if (!t) return '';
    if (/^@@CODEBLOCK_\d+@@$/.test(t)) return t;
    const lines = t.split('\n');
    if (lines.every(l => /^[-*]\s+/.test(l))) return `<ul>${lines.map(l => `<li>${l.replace(/^[-*]\s+/, '')}</li>`).join('')}</ul>`;
    if (lines.every(l => /^\d+\.\s+/.test(l))) return `<ol>${lines.map(l => `<li>${l.replace(/^\d+\.\s+/, '')}</li>`).join('')}</ol>`;
    return `<p>${lines.join('<br>')}</p>`;
  }).join('');

  return html.replace(/@@CODEBLOCK_(\d+)@@/g, (_, i) => codeBlocks[Number(i)] || '');
}

async function safeReadError(response) {
  try { const t = await response.text(); try { return JSON.parse(t)?.error?.message || t; } catch { return t; } } catch { return ''; }
}

function scrollMessagesToBottom() {
  const c = document.querySelector('.chat-area');
  c.scrollTop = c.scrollHeight;
}
