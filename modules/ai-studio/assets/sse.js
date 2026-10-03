// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later
// Kleine SSE-client met een EventSource-achtige API (addEventListener / onerror / close).
//
// Waarom geen echte EventSource? EventSource kan alleen GET. De chat-route is een
// POST (bericht + editorinhoud in de body, CSRF-gecontroleerd), en een CSRF-token in
// een query-string zou in access-logs en Referer-headers belanden. Daarom leest deze
// klasse de text/event-stream met fetch() + ReadableStream, en stuurt het token in de
// header X-CSRF-Token.

export class SseStream {
  /**
   * @param {string} url
   * @param {{csrf: string, body: object}} options
   */
  constructor(url, { csrf, body }) {
    this.listeners = new Map();
    this.onerror = null;
    this.onclose = null;
    this.controller = new AbortController();
    this.closed = false;
    this.run(url, csrf, body);
  }

  addEventListener(name, fn) {
    if (!this.listeners.has(name)) this.listeners.set(name, []);
    this.listeners.get(name).push(fn);
  }

  close() {
    this.closed = true;
    this.controller.abort();
  }

  emit(name, data) {
    for (const fn of this.listeners.get(name) || []) fn(data);
  }

  fail(message) {
    if (this.closed) return;
    if (typeof this.onerror === 'function') this.onerror(new Error(message));
  }

  async run(url, csrf, body) {
    try {
      const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'text/event-stream',
          'X-CSRF-Token': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
        signal: this.controller.signal,
      });

      const type = res.headers.get('Content-Type') || '';
      if (!res.ok || !type.includes('text/event-stream')) {
        let message = 'Verzoek mislukt (HTTP ' + res.status + ').';
        try {
          const json = await res.json();
          if (json && typeof json.error === 'string') message = json.error;
        } catch (_) { /* geen JSON */ }
        this.fail(message);
        return;
      }

      const reader = res.body.getReader();
      const decoder = new TextDecoder('utf-8');
      let buffer = '';
      for (;;) {
        const { value, done } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });
        buffer = buffer.replace(/\r\n/g, '\n');
        let idx;
        while ((idx = buffer.indexOf('\n\n')) !== -1) {
          this.dispatch(buffer.slice(0, idx));
          buffer = buffer.slice(idx + 2);
        }
      }
      if (buffer.trim() !== '') this.dispatch(buffer);
    } catch (err) {
      if (!this.closed) this.fail('Verbinding verbroken.');
    } finally {
      if (typeof this.onclose === 'function') this.onclose();
    }
  }

  dispatch(frame) {
    let name = 'message';
    const data = [];
    for (const line of frame.split('\n')) {
      if (line.startsWith(':') || line === '') continue;
      const colon = line.indexOf(':');
      const field = colon === -1 ? line : line.slice(0, colon);
      let value = colon === -1 ? '' : line.slice(colon + 1);
      if (value.startsWith(' ')) value = value.slice(1);
      if (field === 'event') name = value;
      else if (field === 'data') data.push(value);
    }
    if (data.length === 0) return;
    let parsed;
    try { parsed = JSON.parse(data.join('\n')); } catch (_) { return; }
    this.emit(name, parsed);
  }
}
