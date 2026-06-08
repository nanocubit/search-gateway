# FocusFlow AI — AI-ассистент продуктивности

> Chrome-расширение с AI-чатом, мульти-агентами, графом знаний, фокус-таймером и 20+ инструментами на базе локальных LLM (Ollama) и гибридной БД (DuckDB + Zvec + NeuG + Redis).

---

## Архитектура

```
┌────────────────────────────────────────────────────┐
│                 Chrome Extension                     │
│  ┌──────────┐  ┌──────────┐  ┌──────────────────┐  │
│  │ popup.html │  │content.js│  │  background.js   │  │
│  │ 5 вкладок  │  │ AI чат   │  │ contextMenus     │  │
│  │ 20 фич     │  │ сбор     │  │ команды ⌘E/⌘R   │  │
│  └─────┬─────┘  │ сообщений│  └────────┬─────────┘  │
│        │        └──────────┘           │            │
│        └────────────────┬──────────────┘            │
└─────────────────────────┼──────────────────────────┘
                          │ POST /chat
                          │ POST /save
                          │ GET  /api/*
                          ▼
┌────────────────────────────────────────────────────┐
│              Flask Backend (server.py)               │
│  ┌──────────┐  ┌──────────┐  ┌──────────────────┐  │
│  │ /chat     │  │ /search  │  │ /tools/*         │  │
│  │ /chat/    │  │ /hybrid  │  │ /youtube         │  │
│  │ fullscreen│  │ /similar │  │ /scrape          │  │
│  └────┬─────┘  └────┬─────┘  │ /pdf/upload      │  │
│       │              │        │ /image            │  │
│       │              │        └──────────────────┘  │
│       │              │                               │
│       ▼              ▼                               │
│  ┌─────────────────────────────────────────────┐   │
│  │  Ollama (qwen2.5 / llama3.2 / codellama)     │   │
│  │  http://localhost:11434                       │   │
│  └─────────────────────────────────────────────┘   │
│                                                     │
│  ┌───────┐  ┌──────┐  ┌──────┐  ┌──────────────┐  │
│  │DuckDB  │  │ Zvec │  │ NeuG │  │    Redis     │  │
│  │ SQL    │  │vector│  │graph │  │ pub/sub+cache│  │
│  └───────┘  └──────┘  └──────┘  └──────────────┘  │
└────────────────────────────────────────────────────┘
```

---

## 20 возможностей

### Вкладка AI (Чат)

| # | Функция | Описание |
|---|---------|----------|
| 1 | **Контекстное меню** | Правый клик на любой странице: объяснить, перевести, суммаризировать, объяснить код, найти в истории, сохранить в БЗ. 7 пунктов + клавиатурные команды ⌘E/⌘R/⌘G/⌘S |
| 2 | **AI-суммаризация** | Сжатие содержимого страницы в краткое саммари через Ollama |
| 3 | **YouTube анализ** | Вставьте URL → yt-dlp извлекает субтитры → Ollama суммаризирует → теги + метаданные |
| 4 | **Переключение LLM** | Qwen 2.5, Llama 3.2, CodeLlama (локальные), GPT-4o, Claude 3.5, Gemini (облачные) |
| 5 | **AI-поиск с синтезом** | Гибридный поиск по истории браузера + ответ с цитированием источников |
| 6 | **Генерация изображений** | Stable Diffusion (локально через Automatic1111) или DALL-E 3 (через OpenAI API) |
| 7 | **PDF чат** | Загрузите PDF → pdfplumber извлекает текст → embeddings → вопросы по документу |
| 8 | **Объяснение кода** | Вставьте код → CodeLlama анализирует строка за строкой |
| 9 | **Шаблоны задач** | 6 готовых промптов (письмо, эссе, код, план, саммари, объяснение) |
| 10 | **Голосовой ввод** | Web Speech API — dictation на русском, автоматическая отправка |

### Вкладка Инструменты

| # | Функция | Описание |
|---|---------|----------|
| 11 | **Веб-скрапинг** | requests + BeautifulSoup → извлечение текста со страницы |
| 12 | **Запись процессов** | Авто-инструкции со скриншотами (готовится) |
| — | **Перевод текста** | Ollama переводит на RU/EN/DE/FR/ZH |
| — | **AI поиск** | Быстрый доступ к `/search/hybrid` |

### Вкладка Знания

| # | Функция | Описание |
|---|---------|----------|
| 13 | **Социальное выделение** | Сохранённые URL и текст из контекстного меню |
| 14 | **Граф знаний** | Интерактивный Canvas: узлы — сообщения и агенты, рёбра — связи, частицы |

### Вкладка Агенты

| # | Функция | Описание |
|---|---------|----------|
| 15 | **Мульти-агенты** | Обнаружение AI-агентов (ChatGPT, Claude, Gemini, Ollama) через webNavigation |
| 16 | **Бенчмаркинг** | Реальное время ответа и Quality Score 5 моделей в сравнении |
| 17 | **Fine-tuning** | Мониторинг прогресса обучения персонального адаптера LoRA |

### Вкладка Фокус

| # | Функция | Описание |
|---|---------|----------|
| 18 | **Pomodoro таймер** | 25/15/5/50 минут с кольцевым индикатором |
| 19 | **Список задач** | CRUD с приоритетами (high/medium/low) и фильтрацией |
| 20 | **Obsidian экспорт** | Отправка истории чата в Obsidian vault через ExportDispatcher |

---

## Установка

### 1. Бэкенд (Flask + Ollama)

```bash
# Клонирование
git clone https://github.com/nanocubit/search-gateway.git
cd search-gateway/ai-browser-tracker

# Настройка
cp .env.example .env
# Отредактируйте .env при необходимости

# Установка Python зависимостей
cd server
pip install -r requirements.txt

# Установка Ollama (если ещё нет)
# https://ollama.com/download
ollama pull qwen2.5:3b
ollama pull llama3.2:3b
ollama pull codellama:7b

# Запуск сервера
python server.py
# → http://127.0.0.1:5000
```

### 2. Расширение Chrome

1. Откройте `chrome://extensions`
2. Включите **Режим разработчика**
3. **Загрузить распакованное расширение**
4. Выберите папку `ai-browser-tracker/extension/`
5. Иконка FocusFlow AI появится в панели расширений

### 3. Docker (альтернатива)

```bash
docker compose up -d --build
docker compose exec ollama ollama pull qwen2.5:3b
# → http://127.0.0.1:5000
```

---

## Конфигурация

### `.env`

```env
# Токен авторизации (должен совпадать с background.js)
SECRET_TOKEN=ai-agent-hybrid-token-2026

# Ollama
OLLAMA_URL=http://localhost:11434/api/generate
OLLAMA_MODEL=qwen2.5:3b

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Stable Diffusion (опционально)
SD_API_URL=http://127.0.0.1:7860/sdapi/v1/txt2img

# DALL-E 3 (опционально)
OPENAI_API_KEY=sk-...

# Интеграции
OBSIDIAN_ENABLED=false
OBSIDIAN_VAULT_PATH=/path/to/vault
NOTION_ENABLED=false
NOTION_TOKEN=ntn_...
VSCODE_ENABLED=false
VSCODE_WORKSPACE_PATH=/path/to/workspace
```

### Клавиатурные команды

| Команда | Windows/Linux | macOS | Действие |
|---------|--------------|-------|----------|
| Объяснить выделенное | `Ctrl+Shift+E` | `⌘+Shift+E` | Открыть popup → объяснить текст |
| Перевести на русский | `Ctrl+Shift+R` | `⌘+Shift+R` | Открыть popup → перевод на RU |
| Суммаризировать | `Ctrl+Shift+S` | `⌘+Shift+S` | Открыть popup → саммари |
| Перевести на English | `Ctrl+Shift+G` | `⌘+Shift+G` | Открыть popup → перевод на EN |

---

## API Endpoints (бэкенд)

### AI / Чат

| Метод | Путь | Описание |
|-------|------|----------|
| `POST` | `/chat` | Чат с AI (контекст из истории браузера) |
| `POST` | `/chat/fullscreen` | Полноэкранный чат (кастомная модель/system_prompt/история) |

### Поиск

| Метод | Путь | Описание |
|-------|------|----------|
| `POST` | `/search/similar` | Векторный поиск (Zvec) + графовый контекст (NeuG) |
| `POST` | `/search/hybrid` | Гибридный поиск (ILIKE + вектор + attention score) |

### Данные

| Метод | Путь | Описание |
|-------|------|----------|
| `POST` | `/save` | Сохранение page_visit / chat_message / network_request |
| `GET` | `/api/stats` | Статистика БД (таблицы, векторы, граф) |
| `GET` | `/api/graph/<id>` | Графовый контекст сообщения |
| `GET` | `/api/history/pages` | История посещённых страниц |
| `GET` | `/history/chat` | История сообщений чата |

### Агенты

| Метод | Путь | Описание |
|-------|------|----------|
| `POST` | `/agents/detect` | Регистрация AI-агента |
| `GET` | `/agents/list` | Список активных агентов |

### Инструменты (новые)

| Метод | Путь | Описание |
|-------|------|----------|
| `POST` | `/tools/youtube` | Анализ YouTube (yt-dlp → субтитры → Ollama) |
| `POST` | `/tools/scrape` | Веб-скрапинг (BeautifulSoup) |
| `POST` | `/tools/pdf/upload` | Загрузка PDF (pdfplumber) |
| `POST` | `/tools/image` | Генерация изображений (SD / DALL-E) |
| `POST` | `/api/benchmark` | Бенчмарк моделей Ollama |
| `GET` | `/api/finetune/status` | Статус fine-tuning |

### Экспорт

| Метод | Путь | Описание |
|-------|------|----------|
| `GET` | `/export/<fmt>` | Экспорт в JSON/CSV/MD |
| `POST` | `/integrations/export` | Экспорт в Obsidian/Notion/VSCode |

---

## Структура проекта

```
ai-browser-tracker/
├── extension/                    # Chrome Extension MV3
│   ├── manifest.json             # Манифест v3, permissions, commands
│   ├── popup.html                # FocusFlow AI интерфейс (1288 строк)
│   ├── background.js             # Service worker: contextMenus, трекинг, очередь
│   ├── content.js                # Content script: сбор сообщений, getSelectedText
│   ├── package.json              # Тесты Jest
│   ├── icons/                    # Иконки 16/48/128 px
│   └── tests/                    # Тесты расширения
├── server/                       # Flask бэкенд
│   ├── server.py                 # 503 строки, 20+ эндпоинтов
│   ├── hybrid_db.py              # DuckDB + Zvec + NeuG менеджер
│   ├── requirements.txt          # 18 Python зависимостей
│   ├── Makefile                  # install-deps, build-neug, test
│   ├── core/
│   │   ├── afk_monitor.py        # Мониторинг активности (pynput)
│   │   └── webhooks.py           # Webhook-менеджер (Redis pub/sub)
│   ├── exporters/
│   │   ├── dispatcher.py         # Диспетчер экспорта
│   │   ├── obsidian_exporter.py  # Obsidian MD экспорт
│   │   ├── notion_exporter.py    # Notion API экспорт
│   │   └── vscode_exporter.py    # VS Code Foam экспорт
│   └── tests/                    # pytest тесты
├── web/                          # Веб-интерфейс
│   ├── index.html                # Дашборд
│   ├── chat.html                 # Полноэкранный чат
│   ├── app.js
│   └── chat.js
├── agents/                       # Python AI-агенты
├── docker-compose.yml            # Redis + Ollama + Flask
├── Dockerfile
├── generate_icons.ps1            # Генерация PNG иконок
└── .env.example
```

---

## Разработка

### Запуск тестов

```bash
# Python тесты
cd server && pytest tests/ -v

# JS тесты (Jest)
cd extension && npm test
```

### Добавление нового инструмента

1. Добавьте эндпоинт в `server.py` (POST с декоратором `@app.route`)
2. Добавьте `tool-card` в `popup.html` с `data-tool="имя"`
3. Создайте `div.tool-sub#sub-имя` с UI и обработчиком
4. Обновите README.md

### Сборка

```bash
# Установка NeuG (графовая БД)
cd server && make install

# Полная установка
pip install -r requirements.txt
```

---

## Требования

| Компонент | Минимум | Рекомендуется |
|-----------|---------|---------------|
| Python | 3.10 | 3.12 |
| Ollama | — | qwen2.5:3b, codellama:7b |
| Redis | 6.x | 7.x |
| Chrome | MV3 | Последняя версия |
| RAM (сервер) | 2 ГБ | 8 ГБ (с Ollama) |
| RAM (расширение) | — | 256 МБ |

---

## Лицензия

MIT
