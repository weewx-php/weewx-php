import {feedURL, subscribe} from './feed-client.js';

const statusText = {ready: '', stale: 'Veraltet', pending: 'Ausstehend', unavailable: 'Keine Daten'};

class WeatherWidget extends HTMLElement {
    static observedAttributes = ['api', 'fields', 'title'];
    connectedCallback() { this.start(); }
    disconnectedCallback() { this.stop?.(); }
    attributeChangedCallback() { if (this.isConnected) this.start(); }

    start() {
        this.stop?.();
        const root = this.shadowRoot || this.attachShadow({mode: 'open'});
        root.replaceChildren();
        const style = document.createElement('link');
        style.rel = 'stylesheet';
        style.href = new URL('./weather-widget.css', import.meta.url).href;
        const box = document.createElement('section');
        const title = document.createElement('h2');
        const list = document.createElement('dl');
        const status = document.createElement('p');
        status.setAttribute('role', 'status');
        status.textContent = 'Laden …';
        box.append(title, list, status);
        root.append(style, box);
        try {
            const url = new URL(feedURL(this.getAttribute('api') || ''));
            const selection = this.getAttribute('fields');
            if (selection) url.searchParams.set('fields', selection);
            this.stop = subscribe(url.href, (feed, error) => {
                if (feed) {
                    title.textContent = this.getAttribute('title') || feed.title || 'Wetter';
                    list.replaceChildren();
                    for (const value of Object.values(feed.data)) {
                        if (!value || value.type !== 'value') continue;
                        const row = document.createElement('div');
                        const term = document.createElement('dt');
                        const description = document.createElement('dd');
                        const number = document.createElement('strong');
                        const stamp = document.createElement('small');
                        term.textContent = String(value.label || 'Wert');
                        number.textContent = typeof value.formatted === 'string' ? value.formatted : '—';
                        const date = typeof value.asOf === 'number' ? new Intl.DateTimeFormat('de-DE', {
                            timeZone: feed.timezone || 'UTC', day: '2-digit', month: '2-digit', year: 'numeric',
                            hour: '2-digit', minute: '2-digit', second: '2-digit', timeZoneName: 'short',
                        }).format(new Date(value.asOf * 1000)) : '';
                        stamp.textContent = [{live: 'Live', archive: 'Archiv', astronomy: 'Astronomie'}[value.source] || '', date,
                            statusText[value.status] || ''].filter(Boolean).join(' · ');
                        description.append(number, stamp);
                        row.append(term, description);
                        list.append(row);
                    }
                }
                status.textContent = error ? 'Verbindung unterbrochen' : '';
                status.hidden = !error;
            });
        } catch {
            status.textContent = 'Ungültige API-Adresse';
        }
    }
}

if (!customElements.get('weewx-weather')) customElements.define('weewx-weather', WeatherWidget);
