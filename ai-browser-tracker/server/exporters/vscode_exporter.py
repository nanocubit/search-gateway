import os
import datetime
import hashlib
import re
import json
from typing import Dict, List, Any, Optional
from pathlib import Path

class VSCodeExporter:
    def __init__(self, config: Dict[str, Any]):
        self.workspace_path = Path(config.get('workspace_path', ''))
        self.group_by = config.get('group_by', 'day')
        self.create_foam_config = config.get('create_foam_config', True)
        self.enabled = config.get('enabled', False)
        self._exported_hashes: set = set()

    @property
    def is_available(self) -> bool:
        if not self.enabled: return False
        return self.workspace_path.exists() and self.workspace_path.is_dir()

    def init_workspace(self) -> bool:
        if not self.enabled: return False
        try:
            for subdir in ['daily', 'platforms', 'sessions']:
                (self.workspace_path / subdir).mkdir(parents=True, exist_ok=True)
            if self.create_foam_config:
                vscode_dir = self.workspace_path / '.vscode'
                vscode_dir.mkdir(exist_ok=True)
                settings_path = vscode_dir / 'settings.json'
                if not settings_path.exists():
                    settings = {
                        "foam.files.newNotePath": "daily",
                        "foam.files.defaultExtension": ".md",
                        "foam.graph.showIcons": True,
                        "foam.openDailyNote.titleFormat": "yyyy-mm-dd",
                        "foam.openDailyNote.directory": "daily",
                        "markdown.preview.breaks": True,
                        "recommendations": ["foam.foam-vscode", "yzhang.markdown-all-in-one", "davidanson.vscode-markdownlint"]
                    }
                    with open(settings_path, 'w', encoding='utf-8') as f:
                        json.dump(settings, f, indent=2)
                        f.write('\n')
            return True
        except Exception as e:
            print(f"[VSCode] Init error: {e}"); return False

    def export_message(self, message: Dict[str, Any]) -> Optional[str]:
        if not self.is_available: return None
        msg_hash = self._hash_message(message)
        if msg_hash in self._exported_hashes: return None
        self._exported_hashes.add(msg_hash)
        try:
            if self.group_by == 'day': return self._append_to_daily(message)
            elif self.group_by == 'platform': return self._append_to_platform(message)
            elif self.group_by == 'session': return self._create_session_note(message)
            else: return self._append_to_daily(message)
        except Exception as e:
            print(f"[VSCode] Export error: {e}"); return None

    def export_batch(self, messages: List[Dict[str, Any]]) -> Dict[str, int]:
        stats = {'exported': 0, 'skipped': 0, 'errors': 0}
        for msg in messages:
            try:
                result = self.export_message(msg)
                if result: stats['exported'] += 1
                else: stats['skipped'] += 1
            except Exception: stats['errors'] += 1
        return stats

    def _append_to_daily(self, message: Dict[str, Any]) -> str:
        ts = self._parse_timestamp(message.get('timestamp'))
        day_str = ts.strftime('%Y-%m-%d')
        file_path = self.workspace_path / 'daily' / f'{day_str}.md'
        block = self._format_message_block(message, ts)
        if file_path.exists():
            content = file_path.read_text(encoding='utf-8')
            if block.strip() in content: return str(file_path)
            with open(file_path, 'a', encoding='utf-8') as f: f.write('\n' + block)
        else:
            post = self._create_frontmatter({'date': day_str, 'tags': ['vscode-tracker', 'daily']}, f'# {day_str}\n\nBrowser history for the day.\n')
            file_path.write_text(post + '\n' + block, encoding='utf-8')
        return str(file_path)

    def _append_to_platform(self, message: Dict[str, Any]) -> str:
        platform = self._sanitize_filename(message.get('platform', 'unknown'))
        file_path = self.workspace_path / 'platforms' / f'{platform}.md'
        ts = self._parse_timestamp(message.get('timestamp'))
        block = self._format_message_block(message, ts)
        if not file_path.exists():
            post = self._create_frontmatter({'platform': platform, 'tags': ['vscode-tracker', f'platform-{platform.lower()}']}, f'# {platform}\n\nChat history with {platform}.\n')
            file_path.write_text(post + '\n' + block, encoding='utf-8')
        else:
            content = file_path.read_text(encoding='utf-8')
            if block.strip() not in content:
                with open(file_path, 'a', encoding='utf-8') as f: f.write('\n' + block)
        return str(file_path)

    def _create_session_note(self, message: Dict[str, Any]) -> str:
        ts = self._parse_timestamp(message.get('timestamp'))
        session_id = ts.strftime('%Y%m%d_%H%M%S')
        platform = self._sanitize_filename(message.get('platform', 'unknown'))
        file_path = self.workspace_path / 'sessions' / f'{platform}_{session_id}.md'
        if file_path.exists(): return str(file_path)
        block = self._format_message_block(message, ts)
        post = self._create_frontmatter({'platform': platform, 'date': ts.isoformat(), 'role': message.get('role', ''), 'tags': ['vscode-tracker', 'session', platform.lower()], 'url': message.get('url', '')}, '')
        file_path.write_text(post + block, encoding='utf-8')
        return str(file_path)

    def _create_frontmatter(self, meta: dict, content: str) -> str:
        lines = ['---']
        for key, value in meta.items():
            if isinstance(value, list):
                lines.append(f'{key}:')
                for item in value: lines.append(f'  - {item}')
            else: lines.append(f'{key}: {value}')
        lines.append('---\n')
        return '\n'.join(lines) + content

    def _format_message_block(self, message: Dict[str, Any], ts) -> str:
        role = message.get('role', 'unknown'); content = message.get('content', '').strip(); url = message.get('url', ''); platform = message.get('platform', 'unknown')
        emoji = 'U' if role == 'user' else 'AI'; time_str = ts.strftime('%H:%M:%S')
        lines = [f'\n### {emoji} {role.title()} - {time_str}', '', content, '']
        if url: lines.append(f'> [{platform}]({url})')
        lines.extend(['', '---'])
        return '\n'.join(lines)

    def _parse_timestamp(self, ts):
        if isinstance(ts, datetime.datetime): return ts
        if not ts: return datetime.datetime.now()
        try: return datetime.datetime.fromisoformat(ts.replace('Z', '+00:00'))
        except Exception: return datetime.datetime.now()

    def _sanitize_filename(self, name: str) -> str:
        return re.sub(r'[^\w\-_. ]', '_', name)[:100] or 'unknown'

    def _hash_message(self, message: Dict[str, Any]) -> str:
        raw = f"{message.get('platform')}|{message.get('role')}|{message.get('content', '')[:200]}"
        return hashlib.sha256(raw.encode('utf-8')).hexdigest()[:16]
