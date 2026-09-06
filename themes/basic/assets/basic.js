'use strict';

(() => {
    const rows = [...document.querySelectorAll('[data-field]')];
    const status = document.querySelector('#connection');
    let timer;
    let controller;
    async function refresh() {
        clearTimeout(timer);
        if (document.hidden || controller) return;
        controller = new AbortController();
        const timeout = setTimeout(() => controller?.abort(), 8000);
        try {
            const response = await fetch('data.php', {cache: 'no-store', signal: controller.signal});
            if (!response.ok) throw new Error('Unavailable');
            const data = await response.json();
            if (!data.fields || typeof data.name !== 'string') throw new Error('Invalid snapshot');
            if (data.language !== document.documentElement.lang) { location.reload(); return; }
            for (const row of rows) {
                const field = data.fields[row.dataset.field];
                if (!field || typeof field.formatted !== 'string' || typeof field.time !== 'string') throw new Error('Invalid field');
                row.querySelector('[data-value]').textContent = field.formatted;
                row.querySelector('[data-time]').textContent = field.time;
                row.querySelector('[data-status]').textContent = field.statusText;
            }
            document.querySelector('h1').textContent = data.name;
            document.title = data.name;
            status.textContent = '';
        } catch {
            if (!document.hidden) status.textContent = status.dataset.error;
        } finally {
            clearTimeout(timeout);
            controller = null;
            if (!document.hidden) timer = setTimeout(refresh, 15000);
        }
    }
    document.addEventListener('visibilitychange', () => {
        clearTimeout(timer);
        if (document.hidden) controller?.abort();
        else refresh();
    });
    timer = setTimeout(refresh, 15000);
})();
