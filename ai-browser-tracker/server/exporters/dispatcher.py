import json
import threading
import time
from typing import Dict, Any, Optional
import redis
from .obsidian_exporter import ObsidianExporter
from .notion_exporter import NotionExporter
from .vscode_exporter import VSCodeExporter

class ExportDispatcher:
    def __init__(self, config: Dict[str, Any], db_manager, db_lock):
        self.config = config; self.db_manager = db_manager; self.db_lock = db_lock
        self.obsidian = None; self.notion = None; self.vscode = None
        self._thread = None; self._stop_event = threading.Event()
        self._stats = {'obsidian': 0, 'notion': 0, 'vscode': 0, 'errors': 0}
        self._init_exporters()

    def _init_exporters(self):
        if self.config.get('obsidian_enabled', False):
            self.obsidian = ObsidianExporter({'enabled': True, 'vault_path': self.config.get('obsidian_vault_path', ''), 'group_by': self.config.get('obsidian_group_by', 'day'), 'use_wikilinks': self.config.get('obsidian_use_wikilinks', True)})
            if self.obsidian.is_available: self.obsidian.init_vault(); print(f"[Dispatcher] Obsidian ready")
        if self.config.get('notion_enabled', False):
            self.notion = NotionExporter({'enabled': True, 'token': self.config.get('notion_token', ''), 'database_id': self.config.get('notion_database_id', ''), 'batch_size': self.config.get('notion_batch_size', 5)})
            if self.notion.is_available: print("[Dispatcher] Notion ready")
        if self.config.get('vscode_enabled', False):
            self.vscode = VSCodeExporter({'enabled': True, 'workspace_path': self.config.get('vscode_workspace_path', ''), 'group_by': self.config.get('vscode_group_by', 'day'), 'create_foam_config': self.config.get('vscode_create_foam_config', True)})
            if self.vscode.is_available: self.vscode.init_workspace(); print(f"[Dispatcher] VSCode ready")

    def start(self):
        if not (self.obsidian or self.notion or self.vscode): return
        auto = any(self.config.get(k) for k in ['obsidian_auto_sync', 'notion_auto_sync', 'vscode_auto_sync'])
        if not auto: return
        self._thread = threading.Thread(target=self._listen_loop, daemon=True); self._thread.start()
        print("[Dispatcher] Auto-sync started")

    def stop(self):
        self._stop_event.set()
        if self._thread: self._thread.join(timeout=5)

    def _listen_loop(self):
        retry_delay = 1
        while not self._stop_event.is_set():
            try:
                r = redis.Redis(host=self.config.get('redis_host', '127.0.0.1'), port=int(self.config.get('redis_port', 6379)), db=int(self.config.get('redis_db', 0)), decode_responses=True)
                pubsub = r.pubsub(); pubsub.subscribe('new_message'); retry_delay = 1
                for message in pubsub.listen():
                    if self._stop_event.is_set(): break
                    if message['type'] == 'message':
                        try: self._handle_message(json.loads(message['data']))
                        except Exception as e: print(f"[Dispatcher] Error: {e}"); self._stats['errors'] += 1
            except redis.ConnectionError as e:
                print(f"[Dispatcher] Redis lost: {e}. Retry in {retry_delay}s..."); time.sleep(retry_delay); retry_delay = min(retry_delay * 2, 30)
            except Exception as e: print(f"[Dispatcher] Unexpected: {e}"); time.sleep(5)

    def _handle_message(self, data: Dict[str, Any]):
        if data.get('platform') == 'popup_chat': return
        msg_id = data.get('message_id')
        if not msg_id: return
        message = None
        with self.db_lock:
            try:
                rows = self.db_manager.conn.execute("SELECT id, platform, role, content, url, timestamp FROM chat_messages WHERE id=?", [msg_id]).fetchone()
                if rows: message = {'id': rows[0], 'platform': rows[1], 'role': rows[2], 'content': rows[3], 'url': rows[4], 'timestamp': rows[5].isoformat() if rows[5] else None}
            except Exception: return
        if not message: return
        for name, exporter, auto_key in [('obsidian', self.obsidian, 'obsidian_auto_sync'), ('notion', self.notion, 'notion_auto_sync'), ('vscode', self.vscode, 'vscode_auto_sync')]:
            if exporter and self.config.get(auto_key, False):
                try:
                    if exporter.export_message(message): self._stats[name] += 1
                except Exception as e: print(f"[Dispatcher] {name} error: {e}"); self._stats['errors'] += 1

    def export_historical(self, data_type='chat', limit=100, platform=None):
        results = {}
        messages = self.db_manager.get_chat_messages(platform=platform, limit=limit) if data_type == 'chat' else self.db_manager.get_page_visits(limit=limit) if data_type == 'pages' else None
        if messages is None: return {'error': f'Unknown type: {data_type}'}
        for name, exporter in [('obsidian', self.obsidian), ('notion', self.notion), ('vscode', self.vscode)]:
            if exporter:
                try: results[name] = exporter.export_batch(messages)
                except Exception as e: results[name] = {'error': str(e)}
        return results

    def get_stats(self):
        return {
            'obsidian_available': self.obsidian.is_available if self.obsidian else False,
            'notion_available': self.notion.is_available if self.notion else False,
            'vscode_available': self.vscode.is_available if self.vscode else False,
            'auto_sync_active': self._thread is not None and self._thread.is_alive(),
            'exported': self._stats.copy()
        }
