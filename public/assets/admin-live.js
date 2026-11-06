(() => {
  'use strict';
  const el = document.querySelector('[data-room-updates]');
  if (!el) return;
  let cursor = Number(el.dataset.cursor || 0);
  const roomId = el.dataset.roomId;
  if (!roomId) return;
  const state = document.createElement('p');
  state.textContent = '新着コメントを確認しています';
  el.prepend(state);
  let active = true;
  async function poll() {
    if (!active || document.hidden) return;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 8000);
    try {
      const query = new URLSearchParams({route: 'admin-updates', room_id: roomId, after: String(cursor)});
      const response = await fetch('?' + query.toString(), {credentials:'same-origin', cache:'no-store', signal:controller.signal});
      if (!response.ok) {state.textContent = '更新を取得できません'; return;}
      const data = await response.json();
      if (!Array.isArray(data.comments)) return;
      for (const comment of data.comments) {
        const article = document.createElement('article');
        const date = document.createElement('small');
        date.textContent = comment.created_at || '';
        const body = document.createElement('p');
        body.textContent = comment.comment_text || '';
        article.append(date, body);
        const fields = comment.values || {};
        for (const [key, value] of Object.entries(fields)) {
          if (key === 'comment' || typeof value !== 'string') continue;
          const detail = document.createElement('div');
          detail.textContent = key + '：' + value;
          article.append(detail);
        }
        const hr = document.createElement('hr');
        el.prepend(hr);
        el.prepend(article);
        cursor = Math.max(cursor, Number(comment.id || 0));
      }
      state.textContent = data.comments.length ? '新着を反映しました' : '新着を確認中';
    } catch (e) {
      state.textContent = '通信が不安定です。自動で再試行します';
    } finally {
      clearTimeout(timeout);
    }
  }
  document.addEventListener('visibilitychange', () => {if (!document.hidden) void poll();});
  window.addEventListener('pagehide', () => { active = false; });
  void poll();
  const timer = setInterval(() => {if(active) void poll(); else clearInterval(timer);}, 3000);
})();