import {subscribe} from './feed-client.js';
import {temperatureHumidity, dailyRain, rainAccumulation, monthlyComparison, timeFormat} from './chart-recipes.js';

const targets = [...document.querySelectorAll('[data-chart]')];
const charts = targets.map(element => echarts.init(element, null, {renderer: 'canvas'}));
const observer = new ResizeObserver(() => charts.forEach(chart => chart.resize()));
targets.forEach(element => observer.observe(element));
const table = document.querySelector('#chart-table tbody');
const status = document.querySelector('#chart-status');
let previous = null;
const stop = subscribe(document.body.dataset.api, (feed, error) => {
    status.textContent = error ? 'API nicht erreichbar' : '';
    if (!feed || feed === previous) return;
    previous = feed;
    const data = feed.data, zone = feed.timezone;
    [temperatureHumidity(data, zone), dailyRain(data.rain7d, zone), rainAccumulation(data.rain24h, zone),
        monthlyComparison(data.rainComparison, zone)].forEach((option, i) => {
            option.aria = {enabled: true, label: {description: targets[i].getAttribute('aria-label') + '. Werte stehen in der Datentabelle.'}};
            charts[i].setOption(option, {replaceMerge: ['series', 'dataset']});
        });
    for (const name of ['temperature24h', 'rain7d', 'rain24h', 'rainComparison']) {
        const field = data[name];
        const result = document.querySelector(`[data-status="${name}"]`);
        const stamps = field?.asOf ? timeFormat(zone, true)(field.asOf * 1000) : '';
        const states = {ready: '', stale: 'Aktualisierung ausstehend', pending: 'Ausstehend', unavailable: 'Keine Daten'};
        result.textContent = [stamps, states[field?.status] || '',
            name === 'rainComparison' && !(field?.periods?.points?.length) ? 'Keine vollständigen Vergleichsjahre' : ''].filter(Boolean).join(' · ');
    }
    table.replaceChildren();
    for (const [name, series] of Object.entries(data)) {
        if (series.type !== 'series') continue;
        for (const point of series.points) {
            const row = document.createElement('tr');
            for (const value of [name, timeFormat(zone, true)(point.start * 1000), timeFormat(zone, true)(point.end * 1000),
                point.value === null ? '—' : new Intl.NumberFormat('de-DE', {maximumFractionDigits: 2}).format(point.value),
                series.unit || '', point.coverage === null ? '—' : `${Math.round(point.coverage * 100)} %`]) {
                const cell = document.createElement('td'); cell.textContent = value; row.append(cell);
            }
            table.append(row);
        }
    }
});
window.addEventListener('pagehide', () => { stop(); observer.disconnect(); charts.forEach(chart => chart.dispose()); }, {once: true});
// Back/forward-cache restoration remounts chart instances and subscriptions.
window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
