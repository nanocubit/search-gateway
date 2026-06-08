import datetime
import hashlib
import time
import threading
from typing import Dict, List, Any, Optional

try:
    from notion_client import Client as NotionClient
    NOTION_AVAILABLE = True
except ImportError:
    NOTION_AVAILABLE = False
    NotionClient = None

class NotionExporter:
    def __init__(self, config: Dict[str, Any]):
        self.enabled = config.get('enabled', False)
        self.token = config.get('token', '')
        self.database_id = config.get('database_id', '')
        self.batch_size = int(config.get('batch_size', 5))
        self.client = None
        self._exported_hashes: set = set()
        self._rate_limit_lock = threading.Lock()
        self._last_request_time = 0.0
        if self.enabled and NOTION_AVAILABLE and self.token and self.database_id:
            try: self.client = NotionClient(auth=self.token)
            except Exception as e: print(f"[Notion] Init error: {e}"); self.client = None

    @property
    def is_available(self) -> bool:
        return self.enabled and self.client is not None

    def _rate_limit_wait(self):
        with self._rate_limit_lock:
            now = time.time()
            if now - self._last_request_time < 0.35: time.sleep(0.35 - (now - self._last_request_time))
            self._last_request_time = time.time()

    def _hash_message(self, message: Dict[str, Any]) -> str:
        raw = f"{message.get('platform')}|{message.get('role')}|{message.get('content', '')[:200]}"
        return hashlib.sha256(raw.encode('utf-8')).hexdigest()[:16]

    def _is_duplicate(self, msg_hash: str) -> bool:
        if not self.is_available: return False
        try:
            self._rate_limit_wait()
            response = self.client.databases.query(database_id=self.database_id, filter={'property': 'Hash', 'rich_text': {'equals': msg_hash}}, page_size=1)
            return len(response.get('results', [])) > 0
        except Exception: return False

    def export_message(self, message: Dict[str, Any]) -> Optional[str]:
        if not self.is_available: return None
        msg_hash = self._hash_message(message)
        if msg_hash in self._exported_hashes: return None
        if self._is_duplicate(msg_hash): self._exported_hashes.add(msg_hash); return None
        try:
            self._rate_limit_wait()
            page = self._create_page(message, msg_hash)
            self._exported_hashes.add(msg_hash)
            return page.get('id')
        except Exception as e: print(f"[Notion] Export error: {e}"); return None

    def _create_page(self, message: Dict[str, Any], msg_hash: str) -> Dict:
        ts = self._parse_timestamp(message.get('timestamp'))
        platform = (message.get('platform') or 'unknown')[:100]
        role = (message.get('role') or 'unknown').lower()
        content = message.get('content', '')
        url = message.get('url', '')
        content_segments = self._split_rich_text(content)
        properties = {
            'Platform': {'select': {'name': platform}},
            'Role': {'select': {'name': role}},
            'Timestamp': {'date': {'start': ts.isoformat()}},
            'Hash': {'rich_text': [{'text': {'content': msg_hash}}]},
        }
        if url: properties['URL'] = {'url': url[:2000]}
        title_text = f"{role.title()}: {content[:80]}{'...' if len(content) > 80 else ''}"
        properties['Name'] = {'title': [{'text': {'content': title_text[:2000]}}]}
        children = [{'object': 'block', 'type': 'paragraph', 'paragraph': {'rich_text': content_segments}}]
        return self.client.pages.create(parent={'database_id': self.database_id}, properties=properties, children=children)

    def _split_rich_text(self, content: str) -> List[Dict]:
        segments = []
        i = 0
        while i < len(content):
            segments.append({'type': 'text', 'text': {'content': content[i:i+2000]}})
            i += 2000
        return segments or [{'type': 'text', 'text': {'content': ''}}]

    def export_batch(self, messages: List[Dict[str, Any]]) -> Dict[str, int]:
        stats = {'exported': 0, 'skipped': 0, 'errors': 0}
        for msg in messages:
            try:
                result = self.export_message(msg)
                if result: stats['exported'] += 1
                else: stats['skipped'] += 1
            except Exception: stats['errors'] += 1
        return stats

    def _parse_timestamp(self, ts):
        if isinstance(ts, datetime.datetime): return ts
        if not ts: return datetime.datetime.now()
        try: return datetime.datetime.fromisoformat(ts.replace('Z', '+00:00'))
        except Exception: return datetime.datetime.now()

    def test_connection(self) -> Dict[str, Any]:
        if not NOTION_AVAILABLE: return {'ok': False, 'error': 'notion-client not installed'}
        if not self.client: return {'ok': False, 'error': 'Client not initialized'}
        try:
            self._rate_limit_wait()
            db = self.client.databases.retrieve(self.database_id)
            return {'ok': True, 'title': db.get('title', [{}])[0].get('plain_text', 'Untitled'), 'properties': list(db.get('properties', {}).keys())}
        except Exception as e: return {'ok': False, 'error': str(e)}
