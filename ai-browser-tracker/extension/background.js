const LOCAL_DB_URL = 'http://127.0.0.1:5000/save';
const AGENT_DETECT_URL = 'http://127.0.0.1:5000/agents/detect';
const SECRET_TOKEN = 'ai-agent-hybrid-token-2026';
const STORAGE_KEY = 'pending_messages';

async function sendData(payload) {
  try {
    const response = await fetch(LOCAL_DB_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${SECRET_TOKEN}` },
      body: JSON.stringify(payload)
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
  } catch (err) {
    console.warn('[Tracker] Send failed, queuing:', err.message);
    await addToQueue(payload);
  }
}

async function addToQueue(payload) {
  const { pending_messages } = await chrome.storage.local.get(STORAGE_KEY);
  let queue = pending_messages || [];
  queue.push(payload);
  await chrome.storage.local.set({ [STORAGE_KEY]: queue });
}

async function flushQueue() {
  const { pending_messages } = await chrome.storage.local.get(STORAGE_KEY);
  if (!pending_messages || pending_messages.length === 0) return;
  const toSend = [...pending_messages];
  const sentItems = [];
  for (const msg of toSend) {
    try {
      const resp = await fetch(LOCAL_DB_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${SECRET_TOKEN}` },
        body: JSON.stringify(msg)
      });
      if (resp.ok) sentItems.push(msg);
      else break;
    } catch (e) { break; }
  }
  if (sentItems.length > 0) {
    const remaining = toSend.slice(sentItems.length);
    await chrome.storage.local.set({ [STORAGE_KEY]: remaining });
  }
}

chrome.alarms.create('flush-queue', { periodInMinutes: 1 });
chrome.alarms.onAlarm.addListener((alarm) => { if (alarm.name === 'flush-queue') flushQueue(); });
flushQueue();

function detectAIAgent(url, title) {
  let agentType = null, agentName = null;
  const patterns = [
    { test: /localhost:11434|127\.0\.0\.1:11434/, type: 'ollama', name: 'Ollama' },
    { test: /localhost:8080|127\.0\.0\.1:8080/, type: 'local', name: 'Open WebUI' },
    { test: /chat\.openai\.com|chatgpt\.com/, type: 'cloud', name: 'ChatGPT' },
    { test: /claude\.ai/, type: 'cloud', name: 'Claude' },
    { test: /gemini\.google\.com/, type: 'cloud', name: 'Gemini' },
    { test: /deepseek\.com/, type: 'cloud', name: 'DeepSeek' },
    { test: /perplexity\.ai/, type: 'cloud', name: 'Perplexity' },
  ];
  for (const p of patterns) { if (p.test.test(url)) { agentType = p.type; agentName = p.name; break; } }
  if (agentType && agentName) {
    fetch(AGENT_DETECT_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${SECRET_TOKEN}` },
      body: JSON.stringify({ agent_name: agentName, agent_type: agentType, url })
    }).catch(() => {});
  }
}

chrome.webNavigation.onCompleted.addListener((details) => {
  if (details.frameId === 0) {
    chrome.tabs.get(details.tabId, (tab) => {
      if (tab) { sendData({ type: 'page_visit', url: details.url, title: tab.title }); detectAIAgent(details.url, tab.title); }
    });
  }
});

chrome.webRequest.onBeforeRequest.addListener(
  (details) => {
    const endpoints = ['/api/chat', '/api/generate', '/backend-api', '/v1/chat'];
    if (endpoints.some(e => details.url.includes(e))) sendData({ type: 'network_request', method: details.method, url: details.url });
  },
  { urls: ['<all_urls>'] }
);

chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  if (request.type === 'chat_message') { sendData(request.data); sendResponse({ status: 'ok' }); }
  if (request.type === 'getSelectedText') {
    chrome.tabs.sendMessage(sender.tab?.id || request.tabId, { type: 'getSelectedText' }, (resp) => {
      sendResponse(resp || { text: '' });
    });
    return true;
  }
  return true;
});

const CTX_ACTIONS = {
  'ff-explain': { title: 'Объяснить выделенное', prompt: 'Объясни выделенный текст подробно на русском языке' },
  'ff-translate-ru': { title: 'Перевести на русский', prompt: 'Переведи выделенный текст на русский язык' },
  'ff-translate-en': { title: 'Перевести на English', prompt: 'Translate the selected text to English' },
  'ff-summarize': { title: 'Суммаризировать', prompt: 'Сделай саммари выделенного текста' },
  'ff-code': { title: 'Объяснить код', prompt: 'Объясни этот код подробно, строка за строкой' },
  'ff-search': { title: 'Найти в истории', prompt: 'Найди в истории браузера по выделенному тексту' },
  'ff-save': { title: 'Сохранить в базу знаний', prompt: 'Сохрани выделенный текст в базу знаний' },
};

chrome.runtime.onInstalled.addListener(() => {
  for (const [id, cfg] of Object.entries(CTX_ACTIONS)) {
    chrome.contextMenus.create({
      id,
      title: cfg.title,
      contexts: ['selection'],
    });
  }
});

chrome.contextMenus.onClicked.addListener((info, tab) => {
  if (!tab?.id) return;
  const cfg = CTX_ACTIONS[info.menuItemId];
  if (!cfg) return;
  chrome.tabs.sendMessage(tab.id, { type: 'getSelectedText' }, (response) => {
    const text = response?.text || info.selectionText || '';
    chrome.storage.local.set({
      ffAction: { action: info.menuItemId, text, prompt: cfg.prompt }
    });
    chrome.action.openPopup();
  });
});

chrome.commands.onCommand.addListener((command) => {
  const cfg = CTX_ACTIONS[command];
  if (!cfg) return;
  chrome.tabs.query({ active: true, currentWindow: true }, ([tab]) => {
    if (!tab?.id) return;
    chrome.tabs.sendMessage(tab.id, { type: 'getSelectedText' }, (response) => {
      const text = response?.text || '';
      chrome.storage.local.set({
        ffAction: { action: command, text, prompt: cfg.prompt }
      });
      chrome.action.openPopup();
    });
  });
});
