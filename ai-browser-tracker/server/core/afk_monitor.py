import time
import threading
from typing import Dict, Any

try:
    from pynput import mouse, keyboard
    PYNPUT_AVAILABLE = True
except ImportError:
    PYNPUT_AVAILABLE = False

class AFKMonitor:
    def __init__(self, timeout_sec: int = 120):
        self.timeout_sec = timeout_sec
        self._last_activity_time = time.time()
        self._lock = threading.Lock()
        self._listener_mouse = None
        self._listener_keyboard = None
        self._running = False

    def _on_activity(self, *args, **kwargs):
        with self._lock: self._last_activity_time = time.time()

    def start(self):
        if not PYNPUT_AVAILABLE or self._running: return
        self._running = True
        self._listener_mouse = mouse.Listener(on_move=self._on_activity, on_click=self._on_activity, on_scroll=self._on_activity)
        self._listener_keyboard = keyboard.Listener(on_press=self._on_activity, on_release=self._on_activity)
        self._listener_mouse.daemon = True
        self._listener_keyboard.daemon = True
        self._listener_mouse.start()
        self._listener_keyboard.start()
        print("[AFKMonitor] Started")

    def stop(self):
        if self._listener_mouse: self._listener_mouse.stop()
        if self._listener_keyboard: self._listener_keyboard.stop()
        self._running = False

    @property
    def is_afk(self) -> bool:
        with self._lock: return (time.time() - self._last_activity_time) > self.timeout_sec

    @property
    def idle_seconds(self) -> float:
        with self._lock: return time.time() - self._last_activity_time

    def get_stats(self) -> Dict[str, Any]:
        return {'available': PYNPUT_AVAILABLE, 'is_afk': self.is_afk, 'idle_seconds': int(self.idle_seconds), 'timeout_sec': self.timeout_sec}
