'use strict';

// Fixed dataset only. Avoid overlapping requests and pause hidden kiosk tabs.
let updated = null;
let pending = false;
async function refresh() {
    if (document.hidden || pending) return;
    pending = true;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 8000);
    try {
        const range = new URLSearchParams(location.search).get('range') === '7d' ? '7d' : '24h';
        const response = await fetch(`data.php?range=${range}`, {signal: controller.signal, cache: 'no-store'});
        if (!response.ok) throw new Error('unavailable');
        const snapshot = await response.json();
        for (const element of document.querySelectorAll('[data-value]')) {
            const text = snapshot.formatted[element.dataset.value];
            if (typeof text === 'string') element.textContent = text;
        }
        const live = document.querySelector('[data-live]');
        if (live) {
            live.hidden = snapshot.live.value === null;
            live.textContent = `Live: ${snapshot.liveLabel}${snapshot.live.status === 'stale' ? ' · veraltet' : ''}`;
        }
        const status = document.querySelector('[data-refresh-status]');
        if (status) {
            status.textContent = snapshot.status;
            status.hidden = snapshot.status === '';
        }
        // Reload the SVGs and record cards when the prepared archive snapshot changes.
        const stamp = JSON.stringify([snapshot.updated, ...Object.values(snapshot.data).map(x => x.computedAt ?? x.periods?.computedAt)]);
        if (updated !== null && updated !== stamp) location.reload();
        updated = stamp;
    } catch {
        const status = document.querySelector('[data-refresh-status]');
        if (status) {
            status.textContent = 'Verbindung unterbrochen';
            status.hidden = false;
        }
    } finally {
        clearTimeout(timeout);
        pending = false;
    }
}
setInterval(refresh, 15000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
refresh();
