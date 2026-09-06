"use strict";
(() => {
  const script = document.currentScript;
  if (!script) return;
  const endpoint = new URL('../visit.php', script.src);
  const tick = () => fetch(endpoint, {method:'POST',credentials:'omit',headers:{'X-Weather-Visit':'1'},keepalive:true}).catch(() => {});
  setInterval(() => { if (!document.hidden) tick(); }, 60000);
  if (document.readyState === 'complete') tick();
  else window.addEventListener('load', tick, {once:true});
})();
