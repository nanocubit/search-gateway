import json

AUTH_HEADERS = {'Authorization': 'Bearer ai-agent-hybrid-token-2026'}

def test_chat_endpoint(client):
    resp = client.post('/chat', data=json.dumps({'message': 'Hi'}), content_type='application/json', headers=AUTH_HEADERS)
    assert resp.status_code == 200
    assert resp.get_json()['status'] == 'success'

def test_unauthorized(client):
    resp = client.post('/save', data=json.dumps({'type': 'page_visit', 'url': 'x', 'title': 'x'}), content_type='application/json')
    assert resp.status_code == 403

def test_stats(client):
    resp = client.get('/api/stats')
    assert resp.status_code == 200
    assert 'stats' in resp.get_json()
