const API = 'http://127.0.0.1:5000';
const AUTH_TOKEN = 'ai-agent-hybrid-token-2026';
let messageHistory = [];

const messagesDiv = document.getElementById('messages');
const userInput = document.getElementById('user-input');
const sendBtn = document.getElementById('send-btn');
const modelSelect = document.getElementById('model-select');
const systemPrompt = document.getElementById('system-prompt');
const typingIndicator = document.getElementById('typing-indicator');

marked.setOptions({
    breaks: true,
    highlight: function(code, lang) {
        try {
            return hljs.highlightAuto(code, lang ? [lang] : undefined).value;
        } catch(e) {
            return code;
        }
    }
});

function authHeaders() {
    return { 'Authorization': `Bearer ${AUTH_TOKEN}`, 'Content-Type': 'application/json' };
}

function appendMessage(text, sender) {
    const msgDiv = document.createElement('div');
    msgDiv.className = `message ${sender}`;
    if (sender === 'assistant') {
        try {
            msgDiv.innerHTML = marked.parse(text);
            msgDiv.querySelectorAll('pre code').forEach(block => {
                hljs.highlightElement(block);
            });
        } catch(e) {
            msgDiv.textContent = text;
        }
    } else {
        msgDiv.textContent = text;
    }
    messagesDiv.insertBefore(msgDiv, typingIndicator);
    messagesDiv.scrollTop = messagesDiv.scrollHeight;
}

async function sendMessage() {
    const message = userInput.value.trim();
    if (!message) return;

    appendMessage(message, 'user');
    messageHistory.push({ role: 'user', content: message });
    userInput.value = '';
    sendBtn.disabled = true;
    typingIndicator.style.display = 'block';

    try {
        const resp = await fetch(`${API}/chat/fullscreen`, {
            method: 'POST',
            headers: authHeaders(),
            body: JSON.stringify({
                message,
                history: messageHistory.slice(-20),
                model: modelSelect.value,
                system_prompt: systemPrompt.value || undefined
            })
        });
        const data = await resp.json();
        if (data.status === 'success') {
            appendMessage(data.response, 'assistant');
            messageHistory.push({ role: 'assistant', content: data.response });
        } else {
            appendMessage(`Ошибка: ${data.message || 'Unknown error'}`, 'assistant');
        }
    } catch (e) {
        appendMessage(`Ошибка соединения: ${e.message}`, 'assistant');
    } finally {
        sendBtn.disabled = false;
        typingIndicator.style.display = 'none';
        userInput.focus();
    }
}

sendBtn.addEventListener('click', sendMessage);

userInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
});

document.getElementById('clear-history').addEventListener('click', () => {
    messageHistory = [];
    document.querySelectorAll('.message').forEach(el => el.remove());
});
