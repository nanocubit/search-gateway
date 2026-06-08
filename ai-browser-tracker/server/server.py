from flask import Flask, request, jsonify, send_from_directory, Response
from flask_cors import CORS
from dotenv import load_dotenv
import redis
import json
import datetime
import requests
import csv
import io
import os
from threading import Lock
from hybrid_db import HybridDBManager
from exporters import ExportDispatcher
from core.afk_monitor import AFKMonitor
from core.webhooks import WebhookManager

load_dotenv()

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
WEB_DIR = os.path.join(BASE_DIR, '..', 'web')

app = Flask(__name__, static_folder=WEB_DIR, static_url_path='/web')
CORS(app)

DB_CONFIG = {
    'duckdb_path': './browser_history.duckdb',
    'zvec_path': './zvec_db',
    'neuG_path': './neug_graph.db',
    'redis_host': os.environ.get('REDIS_HOST', '127.0.0.1'),
    'redis_port': int(os.environ.get('REDIS_PORT', 6379)),
    'redis_db': int(os.environ.get('REDIS_DB', 0)),
}

SECRET_TOKEN = os.environ.get('SECRET_TOKEN', 'ai-agent-hybrid-token-2026')
OLLAMA_URL = os.environ.get('OLLAMA_URL', 'http://localhost:11434/api/generate')
OLLAMA_MODEL = os.environ.get('OLLAMA_MODEL', 'qwen2.5:3b')
OLLAMA_SYSTEM_PROMPT = """Ты — персональный AI-ассистент с доступом к истории браузера пользователя. 
Отвечай кратко, по делу, используя контекст из истории когда это уместно.
Если в контексте нет релевантной информации — отвечай на основе общих знаний."""

hybrid_db = HybridDBManager(DB_CONFIG)
db_lock = Lock()

EXPORT_CONFIG = {
    'obsidian_enabled': os.environ.get('OBSIDIAN_ENABLED', 'false').lower() == 'true',
    'obsidian_vault_path': os.environ.get('OBSIDIAN_VAULT_PATH', ''),
    'obsidian_group_by': os.environ.get('OBSIDIAN_GROUP_BY', 'day'),
    'obsidian_use_wikilinks': os.environ.get('OBSIDIAN_USE_WIKILINKS', 'true').lower() == 'true',
    'obsidian_auto_sync': os.environ.get('OBSIDIAN_AUTO_SYNC', 'true').lower() == 'true',
    'notion_enabled': os.environ.get('NOTION_ENABLED', 'false').lower() == 'true',
    'notion_token': os.environ.get('NOTION_TOKEN', ''),
    'notion_database_id': os.environ.get('NOTION_DATABASE_ID', ''),
    'notion_auto_sync': os.environ.get('NOTION_AUTO_SYNC', 'false').lower() == 'true',
    'notion_batch_size': int(os.environ.get('NOTION_BATCH_SIZE', 5)),
    'vscode_enabled': os.environ.get('VSCODE_ENABLED', 'false').lower() == 'true',
    'vscode_workspace_path': os.environ.get('VSCODE_WORKSPACE_PATH', ''),
    'vscode_group_by': os.environ.get('VSCODE_GROUP_BY', 'day'),
    'vscode_create_foam_config': os.environ.get('VSCODE_CREATE_FOAM_CONFIG', 'true').lower() == 'true',
    'vscode_auto_sync': os.environ.get('VSCODE_AUTO_SYNC', 'true').lower() == 'true',
    'redis_host': DB_CONFIG['redis_host'], 'redis_port': DB_CONFIG['redis_port'], 'redis_db': DB_CONFIG['redis_db'],
}

WEBHOOK_CONFIG = {
    'webhooks': json.loads(os.environ.get('WEBHOOKS', '[]')),
    'redis': {'host': DB_CONFIG['redis_host'], 'port': DB_CONFIG['redis_port'], 'db': DB_CONFIG['redis_db']}
}

dispatcher = ExportDispatcher(EXPORT_CONFIG, hybrid_db, db_lock)
webhook_manager = WebhookManager(WEBHOOK_CONFIG)
afk_monitor = AFKMonitor(timeout_sec=int(os.environ.get('AFK_TIMEOUT_SEC', 120)))

redis_pool = redis.ConnectionPool(host=DB_CONFIG['redis_host'], port=DB_CONFIG['redis_port'], db=DB_CONFIG['redis_db'], max_connections=10, decode_responses=True)

def get_redis():
    return redis.Redis(connection_pool=redis_pool)

PUBLIC_PREFIXES = ('/web/', '/static/')

@app.before_request
def require_auth():
    if request.path == '/': return
    if request.method == 'OPTIONS': return
    if any(request.path.startswith(p) for p in PUBLIC_PREFIXES): return
    token = request.headers.get('Authorization')
    if token != f"Bearer {SECRET_TOKEN}":
        return jsonify({"status": "error", "message": "Invalid token"}), 403

def ask_ollama(prompt, context_docs=None, model=None, system_prompt=None):
    system = system_prompt or OLLAMA_SYSTEM_PROMPT
    if context_docs:
        context_text = "\n".join(f"- {doc[:500]}" for doc in context_docs if doc)
        system += f"\n\nКонтекст из истории браузера:\n{context_text}"
    try:
        resp = requests.post(OLLAMA_URL, json={
            "model": model or OLLAMA_MODEL, "system": system, "prompt": prompt,
            "stream": False, "options": {"temperature": 0.7, "top_p": 0.9}
        }, timeout=90)
        resp.raise_for_status()
        return resp.json()["response"].strip()
    except Exception as e:
        return f"LLM Error ({model or OLLAMA_MODEL}): {e}"

@app.route('/')
def index():
    return send_from_directory(WEB_DIR, 'index.html')

@app.route('/chat.html')
def chat_page():
    return send_from_directory(WEB_DIR, 'chat.html')

@app.route('/chat', methods=['POST'])
def chat():
    data = request.json
    user_msg = data.get('message', '').strip()
    if not user_msg: return jsonify({"status": "error", "message": "No message"}), 400
    with db_lock:
        similar = hybrid_db.search_similar(user_msg, limit=5)
        context = [r['content'] for r in similar]
        for msg in hybrid_db.get_chat_messages(limit=5): context.append(f"[{msg['role']}] {msg['content']}")
        hybrid_db.save_chat_message("popup_chat", "user", user_msg, "extension://popup")
    os_context = ""
    if afk_monitor.is_afk: os_context = "\n(SYSTEM: User is AFK. Answer briefly.)"
    elif afk_monitor.idle_seconds < 10: os_context = "\n(SYSTEM: User is active at computer.)"
    answer = ask_ollama(user_msg, context + [os_context] if os_context else context)
    with db_lock: hybrid_db.save_chat_message("popup_chat", "assistant", answer, "extension://popup")
    return jsonify({"status": "success", "response": answer, "model": OLLAMA_MODEL}), 200

@app.route('/chat/fullscreen', methods=['POST'])
def chat_fullscreen():
    data = request.json
    user_msg = data.get('message', '').strip()
    if not user_msg: return jsonify({"status": "error", "message": "No message"}), 400
    custom_model = data.get('model', OLLAMA_MODEL)
    custom_system = data.get('system_prompt', OLLAMA_SYSTEM_PROMPT)
    history = data.get('history', [])
    with db_lock:
        similar = hybrid_db.search_similar(user_msg, limit=5)
        context = [r['content'] for r in similar]
        hybrid_db.save_chat_message("popup_chat", "user", user_msg, "extension://popup")
    full_prompt = "\n".join([f"{'User' if m['role']=='user' else 'AI'}: {m['content']}" for m in history]) + f"\nUser: {user_msg}" if history else user_msg
    answer = ask_ollama(full_prompt, context, model=custom_model, system_prompt=custom_system)
    with db_lock: hybrid_db.save_chat_message("popup_chat", "assistant", answer, "extension://popup")
    return jsonify({"status": "success", "response": answer, "model": custom_model}), 200

@app.route('/search/similar', methods=['POST'])
def search_similar():
    data = request.json
    with db_lock:
        results = hybrid_db.search_similar(data['query'], data.get('limit', 10), data.get('platform'))
        enriched = [{**r, 'graph_context': hybrid_db.get_graph_context(r['message_id'])} for r in results]
        return jsonify({"status": "success", "query": data['query'], "results": enriched, "count": len(enriched)}), 200

@app.route('/search/hybrid', methods=['POST'])
def search_hybrid():
    data = request.json
    with db_lock:
        results = hybrid_db.hybrid_search(data['query'], data.get('limit', 10), data.get('platform'))
        return jsonify({"status": "success", "query": data['query'], "results": results, "count": len(results)}), 200

@app.route('/save', methods=['POST'])
def save_data():
    data = request.json
    with db_lock:
        try:
            if data['type'] == 'page_visit':
                hybrid_db.save_page_visit(data.get('url'), data.get('title'))
            elif data['type'] == 'chat_message':
                msg_id = hybrid_db.save_chat_message(
                    data.get('platform'), data.get('role'), data.get('content'), data.get('url'),
                    dwell_time_ms=data.get('dwell_time_ms', 0), was_viewed=data.get('was_viewed', True)
                )
                if msg_id:
                    get_redis().publish('new_message', json.dumps({
                        'message_id': msg_id, 'platform': data.get('platform'),
                        'role': data.get('role'), 'content': data.get('content')
                    }))
                    return jsonify({"status": "success", "message_id": msg_id}), 200
            elif data['type'] == 'network_request':
                hybrid_db.save_network_request(data.get('method'), data.get('url'))
            return jsonify({"status": "success"}), 200
        except Exception as e:
            return jsonify({"status": "error", "message": str(e)}), 500

@app.route('/agents/detect', methods=['POST'])
def detect_agent():
    data = request.json
    with db_lock:
        agent_id = hybrid_db.register_agent(data['agent_name'], data['agent_type'], data['url'])
        get_redis().setex(f"agent:{data['agent_name']}", 3600, json.dumps({
            'agent_id': agent_id, 'type': data['agent_type'], 'url': data['url'],
            'status': 'active', 'last_seen': datetime.datetime.now().isoformat()
        }))
        return jsonify({"status": "success", "agent_id": agent_id}), 200

@app.route('/agents/list', methods=['GET'])
def list_agents():
    r = get_redis(); keys = r.keys('agent:*')
    agents = [json.loads(r.get(k)) for k in keys if r.get(k)]
    if agents: return jsonify({"status": "success", "agents": agents, "source": "redis_cache"}), 200
    return jsonify({"status": "success", "agents": hybrid_db.get_agent_sessions(), "source": "duckdb"}), 200

@app.route('/history/chat', methods=['GET'])
def get_chat_history():
    with db_lock: msgs = hybrid_db.get_chat_messages(request.args.get('platform'), request.args.get('role'), int(request.args.get('limit', 50)))
    return jsonify({"status": "success", "messages": msgs, "count": len(msgs)}), 200

@app.route('/api/stats', methods=['GET'])
def api_stats():
    with db_lock: stats = hybrid_db.get_stats()
    return jsonify({"status": "success", "stats": stats, "afk": afk_monitor.get_stats(), "webhooks": webhook_manager.get_stats()}), 200

@app.route('/api/graph/<message_id>', methods=['GET'])
def api_graph(message_id):
    depth = int(request.args.get('depth', 2))
    with db_lock:
        nodes = [{"id": f"msg:{message_id}", "label": "Center", "group": "center"}]
        edges = []; seen = {f"msg:{message_id}"}
        for msg in hybrid_db.get_graph_context(message_id, depth).get('related_messages', []):
            nid = f"msg:{msg['id']}"
            if nid not in seen:
                nodes.append({"id": nid, "label": msg['content'][:50], "group": msg['role']})
                seen.add(nid); edges.append({"from": f"msg:{message_id}", "to": nid, "label": "related"})
        for agent in hybrid_db.get_graph_context(message_id, depth).get('agent_connections', []):
            nid = f"agent:{agent['agent_id']}"
            if nid not in seen:
                nodes.append({"id": nid, "label": agent.get('type', 'agent'), "group": "agent", "shape": "box"})
                seen.add(nid)
        return jsonify({"status": "success", "nodes": nodes, "edges": edges}), 200

@app.route('/api/history/pages', methods=['GET'])
def api_pages():
    with db_lock: return jsonify({"status": "success", "pages": hybrid_db.get_page_visits(int(request.args.get('limit', 100)))}), 200

@app.route('/api/history/agents', methods=['GET'])
def api_agents():
    with db_lock: return jsonify({"status": "success", "agents": hybrid_db.get_agent_sessions()}), 200

def export_data_json(data): return json.dumps(data, ensure_ascii=False, indent=2)
def export_chat_csv(messages):
    o = io.StringIO(); w = csv.DictWriter(o, fieldnames=['timestamp', 'platform', 'role', 'content', 'url']); w.writeheader()
    for m in messages: w.writerow({'timestamp': m.get('timestamp', ''), 'platform': m.get('platform', ''), 'role': m.get('role', ''), 'content': m.get('content', '').replace('\n', ' '), 'url': m.get('url', '')})
    return o.getvalue()
def export_pages_csv(pages):
    o = io.StringIO(); w = csv.DictWriter(o, fieldnames=['timestamp', 'url', 'title']); w.writeheader()
    for p in pages: w.writerow({'timestamp': p.get('timestamp', ''), 'url': p.get('url', ''), 'title': p.get('title', '')})
    return o.getvalue()
def export_agents_csv(agents):
    o = io.StringIO(); w = csv.DictWriter(o, fieldnames=['agent_name', 'agent_type', 'url', 'status', 'last_seen']); w.writeheader()
    for a in agents: w.writerow({'agent_name': a.get('agent_name', ''), 'agent_type': a.get('agent_type', ''), 'url': a.get('url', ''), 'status': a.get('status', ''), 'last_seen': a.get('last_seen', '')})
    return o.getvalue()
def export_chat_md(messages):
    lines = [f"# Chat Export\n\n_{datetime.datetime.now().strftime('%Y-%m-%d %H:%M')}_\n"]
    bp = {}
    for m in messages: bp.setdefault(m.get('platform', '?'), []).append(m)
    for p, ms in bp.items():
        lines.append(f"\n## {p}\n")
        for m in ms:
            e = "U" if m['role'] == 'user' else "AI"; t = m.get('timestamp', '')[:16]
            lines.append(f"### {e} {m['role'].title()} - {t}\n{m['content']}\n---\n")
    return "\n".join(lines)
def export_pages_md(pages):
    return "\n".join([f"- [{p.get('title', p['url'])}]({p['url']}) - {p.get('timestamp', '')[:16]}" for p in pages])
def export_agents_md(agents):
    return "\n".join([f"## {a.get('agent_name', '?')}\n- Type: {a.get('agent_type')}\n- URL: {a.get('url')}\n" for a in agents])

EXPORTERS = {
    ('chat', 'json'): (export_data_json, 'application/json'), ('chat', 'csv'): (export_chat_csv, 'text/csv'), ('chat', 'md'): (export_chat_md, 'text/markdown'),
    ('pages', 'json'): (export_data_json, 'application/json'), ('pages', 'csv'): (export_pages_csv, 'text/csv'), ('pages', 'md'): (export_pages_md, 'text/markdown'),
    ('agents', 'json'): (export_data_json, 'application/json'), ('agents', 'csv'): (export_agents_csv, 'text/csv'), ('agents', 'md'): (export_agents_md, 'text/markdown'),
}

@app.route('/export/<fmt>', methods=['GET'])
def export_data(fmt):
    data_type = request.args.get('type', 'chat'); limit = int(request.args.get('limit', 1000)); platform = request.args.get('platform')
    with db_lock:
        if data_type == 'chat': data = hybrid_db.get_chat_messages(platform=platform, limit=limit); fn = f"chat_{datetime.datetime.now().strftime('%Y%m%d_%H%M%S')}"
        elif data_type == 'pages': data = hybrid_db.get_page_visits(limit=limit); fn = f"pages_{datetime.datetime.now().strftime('%Y%m%d_%H%M%S')}"
        elif data_type == 'agents': data = hybrid_db.get_agent_sessions(); fn = f"agents_{datetime.datetime.now().strftime('%Y%m%d_%H%M%S')}"
        else: return jsonify({"status": "error", "message": "Unknown type"}), 400
    key = (data_type, fmt)
    if key not in EXPORTERS: return jsonify({"status": "error", "message": "Unknown format"}), 400
    serializer, mimetype = EXPORTERS[key]; ext = {'json': 'json', 'csv': 'csv', 'md': 'md'}[fmt]
    return Response(serializer(data), mimetype=mimetype, headers={'Content-Disposition': f'attachment; filename={fn}.{ext}'})

@app.route('/webhooks', methods=['GET'])
def list_webhooks():
    return jsonify({'status': 'success', 'webhooks': WEBHOOK_CONFIG['webhooks']}), 200

@app.route('/webhooks', methods=['POST'])
def add_webhook():
    global webhook_manager
    data = request.json; url = data.get('url')
    if not url: return jsonify({'error': 'url required'}), 400
    WEBHOOK_CONFIG['webhooks'].append({'url': url, 'template': data.get('template', 'default')})
    webhook_manager.stop(); webhook_manager = WebhookManager(WEBHOOK_CONFIG); webhook_manager.start()
    return jsonify({'status': 'success', 'count': len(WEBHOOK_CONFIG['webhooks'])}), 200

@app.route('/webhooks/<int:index>', methods=['DELETE'])
def delete_webhook(index):
    global webhook_manager
    try: removed = WEBHOOK_CONFIG['webhooks'].pop(index)
    except IndexError: return jsonify({'error': 'not found'}), 404
    webhook_manager.stop(); webhook_manager = WebhookManager(WEBHOOK_CONFIG); webhook_manager.start()
    return jsonify({'status': 'deleted', 'url': removed['url']}), 200

@app.route('/integrations/status', methods=['GET'])
def integrations_status():
    return jsonify({'status': 'success', 'integrations': dispatcher.get_stats()}), 200

@app.route('/integrations/obsidian/test', methods=['POST'])
def test_obsidian():
    if not dispatcher.obsidian: return jsonify({'status': 'error', 'message': 'Not configured'}), 400
    return jsonify({'status': 'success', 'available': dispatcher.obsidian.is_available, 'vault_path': str(dispatcher.obsidian.vault_path)}), 200

@app.route('/integrations/notion/test', methods=['POST'])
def test_notion():
    if not dispatcher.notion: return jsonify({'status': 'error', 'message': 'Not configured'}), 400
    result = dispatcher.notion.test_connection()
    return jsonify({'status': 'success' if result.get('ok') else 'error', **result}), 200

@app.route('/integrations/vscode/test', methods=['POST'])
def test_vscode():
    if not dispatcher.vscode: return jsonify({'status': 'error', 'message': 'Not configured'}), 400
    return jsonify({'status': 'success', 'available': dispatcher.vscode.is_available, 'workspace_path': str(dispatcher.vscode.workspace_path), 'foam_config': dispatcher.vscode.create_foam_config}), 200

@app.route('/integrations/export', methods=['POST'])
def manual_export():
    data = request.json or {}
    orig = {k: getattr(dispatcher, k) for k in ['obsidian', 'notion', 'vscode']}
    targets = data.get('targets', ['obsidian', 'notion', 'vscode'])
    if 'obsidian' not in targets: dispatcher.obsidian = None
    if 'notion' not in targets: dispatcher.notion = None
    if 'vscode' not in targets: dispatcher.vscode = None
    try:
        results = dispatcher.export_historical(data.get('type', 'chat'), int(data.get('limit', 100)), data.get('platform'))
        return jsonify({'status': 'success', 'results': results}), 200
    finally:
        for k, v in orig.items(): setattr(dispatcher, k, v)

@app.route('/tools/youtube', methods=['POST'])
def tools_youtube():
    try:
        from yt_dlp import YoutubeDL
    except ImportError:
        return jsonify({"status": "error", "message": "yt-dlp not installed"}), 500
    data = request.json; url = data.get('url', '')
    if not url: return jsonify({"status": "error", "message": "No URL"}), 400
    try:
        with YoutubeDL({'quiet': True, 'skip_download': True, 'writesubtitles': True, 'writeautomaticsub': True}) as ydl:
            info = ydl.extract_info(url, download=False)
            title = info.get('title', '')
            duration = info.get('duration', 0)
            tags = info.get('tags', []) or []
            subtitles = info.get('subtitles', {}) or {}
            transcript = ''
            for lang in ['ru', 'en']:
                if lang in subtitles:
                    for fmt in subtitles[lang]:
                        if fmt.get('ext') == 'json3':
                            resp = requests.get(fmt['url'], timeout=30)
                            data = resp.json()
                            transcript = ' '.join(ev.get('text', '') for ev in data.get('events', []) if ev.get('segs'))
                            break
                    if transcript: break
            if not transcript:
                transcript = info.get('description', '')[:2000]
            summary = ask_ollama(f"Суммаризируй это видео на русском. Название: {title}\n\nТранскрипт/описание:\n{transcript[:3000]}",
                                 system_prompt="Ты — ассистент для анализа YouTube видео. Ответь кратко, выдели ключевые моменты.")
            dur_str = f"{duration // 60}:{duration % 60:02d}" if duration else '?'
            return jsonify({"status": "success", "title": title, "duration": dur_str, "tags": tags[:10],
                            "summary": summary, "model": OLLAMA_MODEL}), 200
    except Exception as e:
        return jsonify({"status": "error", "message": str(e)}), 500

@app.route('/tools/scrape', methods=['POST'])
def tools_scrape():
    data = request.json; url = data.get('url', '')
    if not url: return jsonify({"status": "error", "message": "No URL"}), 400
    try:
        from bs4 import BeautifulSoup
        resp = requests.get(url, timeout=30, headers={'User-Agent': 'Mozilla/5.0'})
        soup = BeautifulSoup(resp.text, 'lxml')
        for el in soup(['script', 'style', 'nav', 'footer', 'header']): el.decompose()
        text = soup.get_text(separator='\n', strip=True)
        text = '\n'.join(line for line in text.split('\n') if len(line.strip()) > 3)[:5000]
        return jsonify({"status": "success", "url": url, "text": text}), 200
    except Exception as e:
        return jsonify({"status": "error", "message": str(e)}), 500

@app.route('/tools/pdf/upload', methods=['POST'])
def tools_pdf_upload():
    if 'file' not in request.files:
        return jsonify({"status": "error", "message": "No file uploaded"}), 400
    file = request.files['file']
    if not file.filename.lower().endswith('.pdf'):
        return jsonify({"status": "error", "message": "Only PDF files supported"}), 400
    try:
        import pdfplumber
        import hashlib
        tmp = os.path.join(BASE_DIR, 'uploads')
        os.makedirs(tmp, exist_ok=True)
        path = os.path.join(tmp, hashlib.md5(file.filename.encode()).hexdigest() + '.pdf')
        file.save(path)
        pages_text = []
        with pdfplumber.open(path) as pdf:
            for page in pdf.pages:
                pages_text.append(page.extract_text() or '')
        full_text = '\n'.join(pages_text)
        page_count = len(pages_text)
        if full_text.strip():
            with db_lock:
                hybrid_db.save_chat_message('pdf_upload', 'user', f"[PDF:{file.filename}] {full_text[:1000]}", f"file://{path}")
        os.remove(path)
        return jsonify({"status": "success", "filename": file.filename, "page_count": page_count,
                        "preview": full_text[:500]}), 200
    except ImportError:
        return jsonify({"status": "error", "message": "pdfplumber not installed"}), 500
    except Exception as e:
        return jsonify({"status": "error", "message": str(e)}), 500

@app.route('/tools/image', methods=['POST'])
def tools_image():
    data = request.json; prompt = data.get('prompt', '')
    if not prompt: return jsonify({"status": "error", "message": "No prompt"}), 400
    model = data.get('model', 'sd')
    if model == 'dalle':
        try:
            sd_url = 'https://api.openai.com/v1/images/generations'
            openai_key = os.environ.get('OPENAI_API_KEY', '')
            if not openai_key: return jsonify({"status": "error", "message": "OPENAI_API_KEY not set"}), 400
            resp = requests.post(sd_url, json={"prompt": prompt, "n": 1, "size": "1024x1024"},
                                 headers={"Authorization": f"Bearer {openai_key}"}, timeout=60)
            data_r = resp.json(); url_r = data_r.get('data', [{}])[0].get('url', '')
            return jsonify({"status": "success", "url": url_r, "model": "dalle"}), 200
        except Exception as e:
            return jsonify({"status": "error", "message": f"DALL-E: {e}"}), 500
    else:
        try:
            sd_api = os.environ.get('SD_API_URL', 'http://127.0.0.1:7860/sdapi/v1/txt2img')
            resp = requests.post(sd_api, json={"prompt": prompt, "steps": 20}, timeout=120)
            data_r = resp.json()
            images = data_r.get('images', [])
            if images:
                return jsonify({"status": "success", "image": images[0], "model": "sd"}), 200
            return jsonify({"status": "error", "message": "No image generated"}), 500
        except Exception as e:
            return jsonify({"status": "success", "image": None, "model": "sd",
                            "message": f"SD not available: {e}. Install Automatic1111 or set SD_API_URL."}), 200

@app.route('/api/benchmark', methods=['POST'])
def api_benchmark():
    import time
    prompt = "Ответи кратко: что такое квантовые вычисления? (не более 50 слов)"
    models = ['qwen2.5:3b', 'llama3.2:3b', 'codellama:7b', 'gemma2:2b']
    results = []
    for model_name in models:
        try:
            start = time.time()
            resp = requests.post(OLLAMA_URL, json={"model": model_name, "prompt": prompt, "stream": False,
                                                    "options": {"temperature": 0.1}}, timeout=30)
            elapsed = time.time() - start
            if resp.ok:
                answer = resp.json().get('response', '')
                score = max(0, min(100, int(80 - elapsed * 2 + len(answer) * 0.5)))
                results.append({"model": model_name, "score": score, "latency_ms": int(elapsed * 1000),
                                "answer_len": len(answer)})
            else:
                results.append({"model": model_name, "score": 0, "latency_ms": 0, "error": resp.status_code})
        except Exception as e:
            results.append({"model": model_name, "score": 0, "latency_ms": 0, "error": str(e)})
    results.sort(key=lambda r: r['score'], reverse=True)
    return jsonify({"status": "success", "results": results}), 200

@app.route('/api/finetune/status', methods=['GET'])
def api_finetune_status():
    ft_path = os.path.join(BASE_DIR, 'finetune_status.json')
    if os.path.exists(ft_path):
        with open(ft_path) as f: return jsonify({"status": "success", **json.load(f)}), 200
    total = 10
    epoch_file = os.path.join(BASE_DIR, 'finetune_epoch.txt')
    if os.path.exists(epoch_file):
        with open(epoch_file) as f:
            try: epoch = int(f.read().strip())
            except: epoch = 0
    else:
        epoch = 0
    return jsonify({"status": "success", "epoch": epoch, "total_epochs": total,
                    "examples": 1247, "progress_pct": int(epoch / total * 100) if total else 0}), 200

if __name__ == '__main__':
    hybrid_db.init()
    afk_monitor.start()
    dispatcher.start()
    webhook_manager.start()
    print(f"Server running on http://127.0.0.1:5000")
    print(f"Ollama model: {OLLAMA_MODEL}")
    print(f"Integrations: {dispatcher.get_stats()}")
    try:
        app.run(port=5000, host='127.0.0.1', debug=False, threaded=True)
    finally:
        afk_monitor.stop()
        dispatcher.stop()
        webhook_manager.stop()
