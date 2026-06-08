def test_import():
    from agent_client import HybridBrowserDataClient
    c = HybridBrowserDataClient()
    assert c.headers.get('Authorization') is not None
