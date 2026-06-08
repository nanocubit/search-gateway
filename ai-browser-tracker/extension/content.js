(function() {
  const MAX_PROCESSED = 5000;
  const processedMessages = new Set();

  function addProcessed(hash) {
    if (processedMessages.size >= MAX_PROCESSED) {
      const iter = processedMessages.values();
      for (let i = 0; i < 1000; i++) processedMessages.delete(iter.next().value);
    }
    processedMessages.add(hash);
  }

  function getMessageHash(role, content) { return `${role}:${content.substring(0, 200)}`; }

  function extractChatMessages() {
    const messages = [];
    const url = window.location.href;
    const host = window.location.hostname;

    if (host.includes('chat.openai.com') || host.includes('chatgpt.com')) {
      document.querySelectorAll('[data-message-author-role]').forEach(el => {
        const role = el.getAttribute('data-message-author-role');
        const text = el.innerText?.trim();
        if (text && text.length > 2) {
          const hash = getMessageHash(role, text);
          if (!processedMessages.has(hash)) { addProcessed(hash); messages.push({ role, content: text, url }); }
        }
      });
    } else if (host.includes('claude.ai')) {
      document.querySelectorAll('[data-testid="user-message"], [data-testid="assistant-message"]').forEach(el => {
        const role = el.getAttribute('data-testid').includes('user') ? 'user' : 'assistant';
        const text = el.innerText?.trim();
        if (text && text.length > 2) {
          const hash = getMessageHash(role, text);
          if (!processedMessages.has(hash)) { addProcessed(hash); messages.push({ role, content: text, url }); }
        }
      });
    } else if (host.includes('gemini.google.com')) {
      document.querySelectorAll('.query-content, .response-container').forEach(el => {
        const role = el.classList.contains('query-content') ? 'user' : 'assistant';
        const text = el.innerText?.trim();
        if (text && text.length > 2) {
          const hash = getMessageHash(role, text);
          if (!processedMessages.has(hash)) { addProcessed(hash); messages.push({ role, content: text, url }); }
        }
      });
    } else if (host.includes('deepseek.com')) {
      document.querySelectorAll('.message-content').forEach(el => {
        const role = el.closest('.user-message') ? 'user' : 'assistant';
        const text = el.innerText?.trim();
        if (text && text.length > 2) {
          const hash = getMessageHash(role, text);
          if (!processedMessages.has(hash)) { addProcessed(hash); messages.push({ role, content: text, url }); }
        }
      });
    }
    return messages;
  }

  function sendMessages() {
    const newMessages = extractChatMessages();
    if (newMessages.length === 0) return;
    newMessages.forEach(msg => {
      chrome.runtime.sendMessage({
        type: 'chat_message',
        data: { type: 'chat_message', platform: window.location.hostname, role: msg.role, content: msg.content, url: msg.url || window.location.href }
      });
    });
  }

  setTimeout(() => {
    sendMessages();
    let debounceTimer = null;
    new MutationObserver(() => {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(sendMessages, 800);
    }).observe(document.body, { childList: true, subtree: true });
  }, 2500);

  chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
    if (request.type === 'getSelectedText') {
      sendResponse({ text: window.getSelection().toString().trim() });
    }
    if (request.type === 'getPageContent') {
      sendResponse({ url: window.location.href, title: document.title });
    }
    return true;
  });
})();
