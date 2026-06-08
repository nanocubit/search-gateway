import requests
import json
import redis
import os

REST_URL = os.environ.get('TRACKER_API_URL', 'http://127.0.0.1:5000')
REDIS_HOST = os.environ.get('REDIS_HOST', '127.0.0.1')
REDIS_PORT = int(os.environ.get('REDIS_PORT', 6379))
SECRET_TOKEN = os.environ.get('SECRET_TOKEN', 'ai-agent-hybrid-token-2026')

class HybridBrowserDataClient:
    def __init__(self):
        self.headers = {'Authorization': f'Bearer {SECRET_TOKEN}', 'Content-Type': 'application/json'}
        self.redis_client = redis.Redis(host=REDIS_HOST, port=REDIS_PORT)

    def get_chat_messages(self, platform=None, role=None, limit=50):
        params = {'limit': limit}
        if platform: params['platform'] = platform
        if role: params['role'] = role
        resp = requests.get(f"{REST_URL}/history/chat", params=params, headers=self.headers)
        return resp.json().get('messages', [])

    def search_semantic(self, query_text, limit=10, platform=None):
        body = {'query': query_text, 'limit': limit}
        if platform: body['platform'] = platform
        resp = requests.post(f"{REST_URL}/search/similar", json=body, headers=self.headers)
        return resp.json().get('results', [])

    def get_page_visits(self, limit=50):
        resp = requests.get(f"{REST_URL}/api/history/pages", params={'limit': limit}, headers=self.headers)
        return resp.json().get('pages', [])

    def subscribe_to_messages(self, callback):
        pubsub = self.redis_client.pubsub()
        pubsub.subscribe('new_message')
        for msg in pubsub.listen():
            if msg['type'] == 'message': callback(json.loads(msg['data']))


if __name__ == '__main__':
    c = HybridBrowserDataClient()
    for m in c.get_chat_messages(limit=5): print(f"[{m['role']}] {m['content'][:50]}...")
