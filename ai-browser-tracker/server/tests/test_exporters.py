import json
from pathlib import Path

def test_obsidian_export(tmp_path):
    from exporters.obsidian_exporter import ObsidianExporter
    e = ObsidianExporter({'enabled': True, 'vault_path': str(tmp_path), 'group_by': 'day'})
    e.init_vault()
    r = e.export_message({'platform': 'test', 'role': 'user', 'content': 'Hello', 'url': '', 'timestamp': '2026-01-01T12:00:00'})
    assert r is not None

def test_vscode_export(tmp_path):
    from exporters.vscode_exporter import VSCodeExporter
    e = VSCodeExporter({'enabled': True, 'workspace_path': str(tmp_path), 'group_by': 'day', 'create_foam_config': True})
    e.init_workspace()
    r = e.export_message({'platform': 'test', 'role': 'user', 'content': 'Hello', 'url': '', 'timestamp': '2026-01-01T12:00:00'})
    assert r is not None
    assert (tmp_path / '.vscode' / 'settings.json').exists()

def test_notion_no_deps():
    from exporters.notion_exporter import NotionExporter
    e = NotionExporter({'enabled': True, 'token': 't', 'database_id': 'd'})
    assert isinstance(e.is_available, bool)

def test_dispatcher():
    from exporters.dispatcher import ExportDispatcher
    from unittest.mock import MagicMock
    from threading import Lock
    d = ExportDispatcher({'obsidian_enabled': False, 'notion_enabled': False, 'vscode_enabled': False}, MagicMock(), Lock())
    s = d.get_stats()
    assert s['obsidian_available'] is False
    assert s['vscode_available'] is False
