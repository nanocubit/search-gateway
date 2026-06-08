const API = 'http://127.0.0.1:5000';
const AUTH_TOKEN = 'ai-agent-hybrid-token-2026';

let network = null;

function authHeaders() {
  return { 'Authorization': `Bearer ${AUTH_TOKEN}`, 'Content-Type': 'application/json' };
}
function authFetch(url, options = {}) {
  options.headers = { ...options.headers, ...authHeaders() };
  return fetch(url, options);
}
function escapeHtml(text) {
  if (!text) return '';
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
    if (btn.dataset.tab === 'chat') loadChat();
    if (btn.dataset.tab === 'pages') loadPages();
    if (btn.dataset.tab === 'agents') loadAgents();
    if (btn.dataset.tab === 'integrations') loadIntegrations();
  });
});

async function loadStats() {
  try {
    const resp = await authFetch(`${API}/api/stats`);
    const data = await resp.json();
    if (data.status === 'success') {
      const s = data.stats;
      const afk = data.afk || {};
      document.getElementById('stats').innerHTML = `
        <div class="stat-box"><div class="value">${s.duckdb.chat_messages}</div><div class="label">Сообщений</div></div>
        <div class="stat-box"><div class="value">${s.duckdb.page_visits}</div><div class="label">Страниц</div></div>
        <div class="stat-box"><div class="value">${s.zvec.total_vectors}</div><div class="label">Векторов</div></div>
        <div class="stat-box"><div class="value">${s.neug.total_nodes}</div><div class="label">Узлов графа</div></div>
        <div class="stat-box"><div class="value" style="color:${afk.is_afk ? '#f38ba8' : '#a6e3a1'}">${afk.is_afk ? 'AFK' : 'Active'}</div><div class="label">Пользователь</div></div>
      `;
    }
  } catch (e) { console.error('Stats error:', e); }
}

async function loadGraph(messageId) {
  if (!messageId) messageId = document.getElementById('graph-search').value.trim();
  if (!messageId) { alert('Введите ID сообщения'); return; }
  try {
    const resp = await authFetch(`${API}/api/graph/${encodeURIComponent(messageId)}?depth=2`);
    const data = await resp.json();
    if (data.status !== 'success') { alert('Ошибка: ' + (data.message || 'unknown')); return; }
    const nodes = new vis.DataSet(data.nodes.map(n => ({
      id: n.id, label: n.label, group: n.group, shape: n.shape || 'dot',
      title: n.title || n.label,
      color: {
        background: n.group === 'center' ? '#cba6f7' : n.group === 'user' ? '#f38ba8' :
                  n.group === 'assistant' ? '#a6e3a1' : n.group === 'agent' ? '#89b4fa' : '#45475a',
        border: '#313244'
      }
    })));
    const edges = new vis.DataSet(data.edges.map(e => ({
      from: e.from, to: e.to, label: e.label, arrows: 'to', color: { color: '#585b70' }
    })));
    const container = document.getElementById('graph');
    const options = {
      physics: { stabilization: false, barnesHut: { gravitationalConstant: -3000, springLength: 150 } },
      interaction: { hover: true, tooltipDelay: 200 },
      edges: { smooth: { type: 'continuous' }, width: 1 },
      nodes: { font: { color: '#cdd6f4', size: 12 }, borderWidth: 2 }
    };
    if (network) network.destroy();
    network = new vis.Network(container, { nodes, edges }, options);
  } catch (e) { alert('Ошибка загрузки графа: ' + e.message); }
}

async function loadRandomGraph() {
  try {
    const resp = await authFetch(`${API}/history/chat?limit=1`);
    const data = await resp.json();
    if (data.messages && data.messages.length > 0) {
      document.getElementById('graph-search').value = data.messages[0].id;
      loadGraph(data.messages[0].id);
    } else { alert('Нет сообщений в базе'); }
  } catch (e) { alert('Ошибка: ' + e.message); }
}

async function loadChat() {
  const platform = document.getElementById('chat-platform').value;
  const role = document.getElementById('chat-role').value;
  const limit = document.getElementById('chat-limit').value;
  const list = document.getElementById('chat-list');
  list.innerHTML = '<div class="loading">Загрузка...</div>';
  try {
    const params = new URLSearchParams({ limit });
    if (platform) params.append('platform', platform);
    if (role) params.append('role', role);
    const resp = await authFetch(`${API}/history/chat?${params}`);
    const data = await resp.json();
    if (data.messages && data.messages.length > 0) {
      list.innerHTML = data.messages.map(m => `
        <div class="msg-card ${escapeHtml(m.role)}" onclick="showMessageGraph('${escapeHtml(m.id)}')">
          <div class="msg-header">
            <span class="msg-platform">${escapeHtml(m.platform)}</span>
            <span>${escapeHtml(m.role)} - ${m.timestamp ? escapeHtml(m.timestamp.substring(0, 16)) : ''}</span>
          </div>
          <div class="msg-content">${escapeHtml(m.content.substring(0, 500))}${m.content.length > 500 ? '...' : ''}</div>
        </div>
      `).join('');
    } else {
      list.innerHTML = '<div class="loading">Нет сообщений</div>';
    }
  } catch (e) { list.innerHTML = `<div class="loading">Ошибка: ${escapeHtml(e.message)}</div>`; }
}

function showMessageGraph(id) {
  document.querySelector('[data-tab="graph"]').click();
  document.getElementById('graph-search').value = id;
  loadGraph(id);
}

async function loadPages() {
  const limit = document.getElementById('pages-limit').value;
  const list = document.getElementById('page-list');
  list.innerHTML = '<div class="loading">Загрузка...</div>';
  try {
    const resp = await authFetch(`${API}/api/history/pages?limit=${limit}`);
    const data = await resp.json();
    if (data.pages && data.pages.length > 0) {
      list.innerHTML = data.pages.map(p => `
        <div class="page-item">
          <a href="${escapeHtml(p.url)}" target="_blank" title="${escapeHtml(p.title || p.url)}">${escapeHtml(p.title || p.url)}</a>
          <span class="page-time">${p.timestamp ? escapeHtml(p.timestamp.substring(0, 16)) : ''}</span>
        </div>
      `).join('');
    } else {
      list.innerHTML = '<div class="loading">Нет посещений</div>';
    }
  } catch (e) { list.innerHTML = `<div class="loading">Ошибка: ${escapeHtml(e.message)}</div>`; }
}

async function loadAgents() {
  const list = document.getElementById('agents-list');
  list.innerHTML = '<div class="loading">Загрузка...</div>';
  try {
    const resp = await authFetch(`${API}/api/history/agents`);
    const data = await resp.json();
    if (data.agents && data.agents.length > 0) {
      list.innerHTML = data.agents.map(a => `
        <div class="msg-card assistant">
          <div class="msg-header">
            <span class="msg-platform">${escapeHtml(a.agent_name)}</span>
            <span>${escapeHtml(a.agent_type)} - ${escapeHtml(a.status)}</span>
          </div>
          <div style="font-size:13px;color:#a6adc8">
            URL: <a href="${escapeHtml(a.url)}" target="_blank" style="color:#89b4fa">${escapeHtml(a.url)}</a><br>
            Последняя активность: ${a.last_seen ? escapeHtml(a.last_seen.substring(0, 16)) : 'N/A'}
          </div>
        </div>
      `).join('');
    } else {
      list.innerHTML = '<div class="loading">Нет агентов</div>';
    }
  } catch (e) { list.innerHTML = `<div class="loading">Ошибка: ${escapeHtml(e.message)}</div>`; }
}

function doExport(format) {
  const token = document.getElementById('export-token').value;
  const type = document.getElementById('export-type').value;
  const limit = document.getElementById('export-limit').value;
  const url = `${API}/export/${format}?type=${type}&limit=${limit}`;
  fetch(url, { headers: { 'Authorization': `Bearer ${token}` } })
    .then(resp => { if (!resp.ok) throw new Error('Ошибка экспорта'); return resp.blob(); })
    .then(blob => {
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = `export_${type}.${format}`;
      a.click();
      URL.revokeObjectURL(a.href);
    })
    .catch(e => alert('Ошибка: ' + e.message));
}

async function loadIntegrations() {
  try {
    const resp = await authFetch(`${API}/integrations/status`);
    const data = await resp.json();
    const s = data.integrations;
    document.getElementById('integrations-status').innerHTML = `
      <div style="display:flex;gap:15px;flex-wrap:wrap">
        <div class="stat-box">
          <div class="value" style="color:${s.obsidian_available ? '#a6e3a1' : '#f38ba8'}">${s.obsidian_available ? 'V' : 'X'}</div>
          <div class="label">Obsidian</div>
        </div>
        <div class="stat-box">
          <div class="value" style="color:${s.notion_available ? '#a6e3a1' : '#f38ba8'}">${s.notion_available ? 'V' : 'X'}</div>
          <div class="label">Notion</div>
        </div>
        <div class="stat-box">
          <div class="value" style="color:${s.vscode_available ? '#a6e3a1' : '#f38ba8'}">${s.vscode_available ? 'V' : 'X'}</div>
          <div class="label">VS Code</div>
        </div>
        <div class="stat-box">
          <div class="value" style="color:${s.auto_sync_active ? '#a6e3a1' : '#f9e2af'}">${s.auto_sync_active ? 'Y' : 'P'}</div>
          <div class="label">Auto-sync</div>
        </div>
      </div>
      <div style="margin-top:10px;font-size:12px;color:#a6adc8">
        Экспортировано: Obsidian - ${s.exported.obsidian || 0}, Notion - ${s.exported.notion || 0}, VS Code - ${s.exported.vscode || 0}, Ошибок - ${s.exported.errors || 0}
      </div>
    `;
  } catch (e) {
    document.getElementById('integrations-status').innerHTML =
      `<div style="color:#f38ba8">Ошибка: ${escapeHtml(e.message)}</div>`;
  }
}

async function testObsidian() {
  const el = document.getElementById('obsidian-status');
  el.innerHTML = '<div class="loading">Проверка...</div>';
  try {
    const resp = await authFetch(`${API}/integrations/obsidian/test`, { method: 'POST' });
    const data = await resp.json();
    if (data.available) {
      el.innerHTML = `<div style="color:#a6e3a1">Vault доступен: <code>${escapeHtml(data.vault_path)}</code></div>`;
    } else {
      el.innerHTML = `<div style="color:#f38ba8">X ${escapeHtml(data.message || 'Not available')}</div>`;
    }
  } catch (e) { el.innerHTML = `<div style="color:#f38ba8">Ошибка: ${escapeHtml(e.message)}</div>`; }
}

async function testNotion() {
  const el = document.getElementById('notion-status');
  el.innerHTML = '<div class="loading">Проверка...</div>';
  try {
    const resp = await authFetch(`${API}/integrations/notion/test`, { method: 'POST' });
    const data = await resp.json();
    if (data.ok) {
      el.innerHTML = `<div style="color:#a6e3a1">Подключено: <b>${escapeHtml(data.title)}</b><br>Properties: ${escapeHtml((data.properties || []).join(', '))}</div>`;
    } else {
      el.innerHTML = `<div style="color:#f38ba8">X ${escapeHtml(data.error || 'Not connected')}</div>`;
    }
  } catch (e) { el.innerHTML = `<div style="color:#f38ba8">Ошибка: ${escapeHtml(e.message)}</div>`; }
}

async function testVSCode() {
  const el = document.getElementById('vscode-status');
  el.innerHTML = '<div class="loading">Проверка...</div>';
  try {
    const resp = await authFetch(`${API}/integrations/vscode/test`, { method: 'POST' });
    const data = await resp.json();
    if (data.available) {
      el.innerHTML = `<div style="color:#a6e3a1">Workspace доступен: <code>${escapeHtml(data.workspace_path)}</code><br>Foam config: <b>${data.foam_config ? 'да' : 'нет'}</b></div>`;
    } else {
      el.innerHTML = `<div style="color:#f38ba8">X ${escapeHtml(data.message || 'Not available')}</div>`;
    }
  } catch (e) { el.innerHTML = `<div style="color:#f38ba8">Ошибка: ${escapeHtml(e.message)}</div>`; }
}

async function manualExport() {
  const type = document.getElementById('manual-type').value;
  const limit = document.getElementById('manual-limit').value;
  const targets = Array.from(document.getElementById('manual-targets').selectedOptions).map(o => o.value);
  const el = document.getElementById('manual-result');
  if (targets.length === 0) {
    el.innerHTML = '<span style="color:#f38ba8">Выберите хотя бы одну цель</span>';
    return;
  }
  el.innerHTML = '<span style="color:#f9e2af">Экспорт...</span>';
  try {
    const resp = await authFetch(`${API}/integrations/export`, {
      method: 'POST',
      body: JSON.stringify({ type, limit: parseInt(limit), targets })
    });
    const data = await resp.json();
    if (data.status === 'success') {
      const lines = [];
      for (const [target, stats] of Object.entries(data.results)) {
        if (stats.error) {
          lines.push(`<b>${target}:</b> <span style="color:#f38ba8">${escapeHtml(stats.error)}</span>`);
        } else {
          lines.push(`<b>${target}:</b> экспортировано ${stats.exported || 0}, пропущено ${stats.skipped || 0}`);
        }
      }
      el.innerHTML = `<span style="color:#a6e3a1">Готово</span><br>${lines.join('<br>')}`;
      loadIntegrations();
    } else {
      el.innerHTML = `<span style="color:#f38ba8">Ошибка</span>`;
    }
  } catch (e) {
    el.innerHTML = `<span style="color:#f38ba8">Ошибка: ${escapeHtml(e.message)}</span>`;
  }
}

loadStats();
setInterval(loadStats, 30000);
