/** One polling loop per URL, shared by charts and widgets on this page. */
const feeds = new Map();

export function feedURL(value) {
    const url = new URL(value, document.baseURI);
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) {
        throw new Error('Invalid feed URL');
    }
    url.hash = '';
    return url.href;
}

export function subscribe(url, listener) {
    url = feedURL(url);
    let state = feeds.get(url);
    if (!state) {
        state = {listeners: new Set(), data: null, error: null, etag: null, timer: null,
            controller: null, interval: 15000, failures: 0, stopped: false};
        feeds.set(url, state);
        const publish = () => state.listeners.forEach(fn => fn(state.data, state.error));
        const run = async () => {
            clearTimeout(state.timer);
            if (document.hidden || state.stopped || state.controller) return;
            state.controller = new AbortController();
            const timeout = setTimeout(() => state.controller?.abort(), 8000);
            try {
                const response = await fetch(url, {credentials: 'omit', cache: 'no-store',
                    signal: state.controller.signal, headers: state.etag ? {'If-None-Match': state.etag} : {}});
                if (response.status !== 304) {
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                    const data = await response.json();
                    if (data.version !== 1 || !data.data || typeof data.data !== 'object' || Array.isArray(data.data)) {
                        throw new Error('Invalid feed');
                    }
                    state.data = data;
                    state.etag = response.headers.get('ETag');
                    state.interval = Math.max(15, Math.min(86400, Number(data.pollSeconds) || 15)) * 1000;
                } else if (!state.data) {
                    throw new Error('Missing cached feed');
                }
                state.error = null;
                state.failures = 0;
                if (!state.stopped) publish();
            } catch (error) {
                state.failures++;
                state.error = error;
                if (!state.stopped && !document.hidden) publish();
            } finally {
                clearTimeout(timeout);
                state.controller = null;
                if (!state.stopped && !document.hidden) {
                    state.timer = setTimeout(run, Math.min(Math.max(300000, state.interval), state.interval * 2 ** Math.min(state.failures, 4)));
                }
            }
        };
        state.visibility = () => {
            if (document.hidden) {
                clearTimeout(state.timer);
                state.controller?.abort();
            } else run();
        };
        document.addEventListener('visibilitychange', state.visibility);
        // Add the first subscriber before the initial request starts.
        queueMicrotask(run);
    }
    state.listeners.add(listener);
    if (state.data || state.error) listener(state.data, state.error);
    let subscribed = true;
    return () => {
        if (!subscribed) return;
        subscribed = false;
        state.listeners.delete(listener);
        if (!state.listeners.size) {
            state.stopped = true;
            clearTimeout(state.timer);
            state.controller?.abort();
            document.removeEventListener('visibilitychange', state.visibility);
            feeds.delete(url);
        }
    };
}
