import requests
import json
import threading
import time
import redis
from typing import List, Dict, Any

class WebhookManager:
    def __init__(self, config: Dict[str, Any]):
        self.webhooks: List[Dict[str, Any]] = config.get('webhooks', [])
        self.redis_config = config.get('redis', {})
        self._thread = None
        self._stop_event = threading.Event()

    def start(self):
        if not self.webhooks: return
        self._thread = threading.Thread(target=self._listen, daemon=True)
        self._thread.start()
        print(f"[Webhooks] Started with {len(self.webhooks)} hooks")

    def stop(self):
        self._stop_event.set()
        if self._thread: self._thread.join(timeout=5)

    def _listen(self):
        while not self._stop_event.is_set():
            try:
                r = redis.Redis(host=self.redis_config.get('host', '127.0.0.1'), port=self.redis_config.get('port', 6379), db=self.redis_config.get('db', 0), decode_responses=True)
                pubsub = r.pubsub(); pubsub.subscribe('new_message')
                for message in pubsub.listen():
                    if self._stop_event.is_set(): break
                    if message['type'] == 'message': self._dispatch(json.loads(message['data']))
            except Exception as e:
                print(f"[Webhooks] Error: {e}"); time.sleep(5)

    def _dispatch(self, data: Dict[str, Any]):
        for hook in self.webhooks:
            try: requests.post(hook['url'], json={"text": f"[{data.get('platform')}] {data.get('role')}: {data.get('content', '')[:200]}"}, timeout=5)
            except Exception as e: print(f"[Webhooks] Failed {hook['url']}: {e}")

    def get_stats(self):
        return {'webhooks_count': len(self.webhooks), 'active': self._thread is not None and self._thread.is_alive()}
