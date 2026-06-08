import os
import datetime
import hashlib
import re
from typing import Dict, List, Any, Optional
from pathlib import Path

try:
    import frontmatter
except ImportError:
    frontmatter = None

class ObsidianExporter:
    def __init__(self, config: Dict[str, Any]):
        self.vault_path = Path(config.get('vault_path', ''))
        self.group_by = config.get('group_by', 'day')
        self.use_wikilinks = config.get('use_wikilinks', True)
        self.enabled = config.get('enabled', False)
        self._exported_hashes: set = set()

    @property
    def is_available(self) -> bool:
        if not self.enabled: return False
        return self.vault_path.exists() and self.vault_path.is_dir()

    def init_vault(self) -> bool:
        if not self.enabled: return False
        try:
            for subdir in ['daily', 'platforms', 'sessions', '_assets']:
                (self.vault_path / subdir).mkdir(parents=True, exist_ok=True)
            self._update_index()
            return True
        except Exception as e:
            print(f"[Obsidian] Init error: {e}"); return False

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
            print(f"[Obsidian] Export error: {e}"); return None

    def export_batch(self, messages: List[Dict[str, Any]]) -> Dict[str, int]:
        stats = {'exported': 0, 'skipped': 0, 'errors': 0}
        for msg in messages:
            try:
                result = self.export_message(msg)
                if result: stats['exported'] += 1
                else: stats['skipped'] += 1
            except Exception: stats['errors'] += 1
        self._update_index()
        return stats

    def _append_to_daily(self, message: Dict[str, Any]) -> str:
        ts = self._parse_timestamp(message.get('timestamp'))
        day_str = ts.strftime('%Y-%m-%d')
        file_path = self.vault_path / 'daily' / f'{day_str}.md'
        block = self._format_message_block(message, ts)
        if file_path.exists():
            content = file_path.read_text(encoding='utf-8')
            if block.strip() in content: return str(file_path)
            with open(file_path, 'a', encoding='utf-8') as f: f.write('\n' + block)
        else:
            post = self._create_daily_post(day_str)
            post.content += '\n' + block
            self._write_frontmatter_file(file_path, post)
        return str(file_path)

    def _create_daily_post(self, day_str: str):
        if frontmatter:
            post = frontmatter.Post('')
            post.metadata = {'date': day_str, 'tags': ['browser-tracker', 'daily'], 'type': 'daily-note'}
            post.content = f'# {day_str}\n\nBrowser history for the day.\n'
            return post
        else:
            class P:
                def __init__(self): self.metadata = {}; self.content = ''
            p = P()
            p.metadata = {'date': day_str, 'tags': ['browser-tracker', 'daily']}
            p.content = f'# {day_str}\n\nBrowser history for the day.\n'
            return p

    def _append_to_platform(self, message: Dict[str, Any]) -> str:
        platform = self._sanitize_filename(message.get('platform', 'unknown'))
        file_path = self.vault_path / 'platforms' / f'{platform}.md'
        ts = self._parse_timestamp(message.get('timestamp'))
        block = self._format_message_block(message, ts)
        if not file_path.exists():
            post = self._create_platform_post(platform)
            post.content += '\n' + block
            self._write_frontmatter_file(file_path, post)
        else:
            content = file_path.read_text(encoding='utf-8')
            if block.strip() not in content:
                with open(file_path, 'a', encoding='utf-8') as f: f.write('\n' + block)
        return str(file_path)

    def _create_platform_post(self, platform: str):
        if frontmatter:
            post = frontmatter.Post('')
            post.metadata = {'platform': platform, 'tags': ['browser-tracker', f'platform-{platform.lower()}'], 'type': 'platform-note'}
            post.content = f'# {platform}\n\nChat history with {platform}.\n'
            return post
        else:
            class P:
                def __init__(self): self.metadata = {}; self.content = ''
            p = P()
            p.metadata = {'platform': platform, 'tags': ['browser-tracker']}
            p.content = f'# {platform}\n\nChat history with {platform}.\n'
            return p

    def _create_session_note(self, message: Dict[str, Any]) -> str:
        ts = self._parse_timestamp(message.get('timestamp'))
        session_id = ts.strftime('%Y%m%d_%H%M%S')
        platform = self._sanitize_filename(message.get('platform', 'unknown'))
        file_path = self.vault_path / 'sessions' / f'{platform}_{session_id}.md'
        if file_path.exists(): return str(file_path)
        block = self._format_message_block(message, ts)
        if frontmatter:
            post = frontmatter.Post(block)
            post.metadata = {'platform': platform, 'date': ts.isoformat(), 'role': message.get('role', ''), 'tags': ['browser-tracker', 'session', platform.lower()], 'url': message.get('url', '')}
        else:
            class P:
                def __init__(self, c=''): self.metadata = {}; self.content = c
            post = P(block)
            post.metadata = {'platform': platform, 'date': ts.isoformat(), 'role': message.get('role', ''), 'tags': ['browser-tracker', 'session']}
        self._write_frontmatter_file(file_path, post)
        return str(file_path)

    def _format_message_block(self, message: Dict[str, Any], ts) -> str:
        role = message.get('role', 'unknown')
        content = message.get('content', '').strip()
        url = message.get('url', '')
        platform = message.get('platform', 'unknown')
        emoji = 'U' if role == 'user' else 'AI'
        time_str = ts.strftime('%H:%M:%S')
        lines = [f'\n### {emoji} {role.title()} - {time_str}', '', content, '']
        if url: lines.append(f'> [{platform}]({url})')
        lines.extend(['', '---'])
        return '\n'.join(lines)

    def _update_index(self):
        index_path = self.vault_path / '_index.md'
        lines = ['---', 'tags: [browser-tracker, index]', 'type: index', '---', '', '# AI Browser Tracker', '', 'Auto-generated index.', '', '## Daily Notes', '']
        daily_dir = self.vault_path / 'daily'
        if daily_dir.exists():
            for f in sorted(daily_dir.glob('*.md'), reverse=True)[:30]:
                name = f.stem
                lines.append(f'- [[daily/{name}|{name}]]' if self.use_wikilinks else f'- [{name}](daily/{name}.md)')
        lines.extend(['', '## Platforms', ''])
        platforms_dir = self.vault_path / 'platforms'
        if platforms_dir.exists():
            for f in sorted(platforms_dir.glob('*.md')):
                name = f.stem
                lines.append(f'- [[platforms/{name}|{name}]]' if self.use_wikilinks else f'- [{name}](platforms/{name}.md)')
        try: index_path.write_text('\n'.join(lines), encoding='utf-8')
        except Exception as e: print(f"[Obsidian] Index error: {e}")

    def _parse_timestamp(self, ts):
        import datetime as dt
        if isinstance(ts, dt.datetime): return ts
        if not ts: return dt.datetime.now()
        try: return dt.datetime.fromisoformat(ts.replace('Z', '+00:00'))
        except Exception: return dt.datetime.now()

    def _sanitize_filename(self, name: str) -> str:
        return re.sub(r'[^\w\-_. ]', '_', name)[:100] or 'unknown'

    def _hash_message(self, message: Dict[str, Any]) -> str:
        raw = f"{message.get('platform')}|{message.get('role')}|{message.get('content', '')[:200]}"
        return hashlib.sha256(raw.encode('utf-8')).hexdigest()[:16]

    def _write_frontmatter_file(self, path: Path, post):
        if frontmatter:
            with open(path, 'w', encoding='utf-8') as f: frontmatter.dump(post, f)
        else:
            with open(path, 'w', encoding='utf-8') as f:
                f.write('---\n')
                for k, v in (post.metadata or {}).items():
                    if isinstance(v, list):
                        f.write(f'{k}:\n')
                        for item in v: f.write(f'  - {item}\n')
                    else: f.write(f'{k}: {v}\n')
                f.write('---\n\n')
                f.write(post.content)
