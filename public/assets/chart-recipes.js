// Copyright (c) 2026 Manuel Hilgert <hilman2@gmail.com>

import {weatherText} from './weather-locales.js';

/** Pure option builders: timestamps in milliseconds, values unrounded, nulls preserved. */
export const pairs = series => (series?.points || []).map(p => [p.end * 1000, p.value]);
export const cumulative = series => {
    let sum = 0, complete = true;
    return (series?.points || []).map(p => {
        if (typeof p.value !== 'number' || typeof p.coverage !== 'number' || p.coverage < 1) complete = false;
        if (complete) sum += p.value;
        return [p.end * 1000, complete ? sum : null];
    });
};

export function timeFormat(zone, detailed = false, locale = 'en') {
    const format = new Intl.DateTimeFormat(locale, {timeZone: zone, day: '2-digit', month: '2-digit',
        hour: '2-digit', minute: '2-digit', ...(detailed ? {year: 'numeric', timeZoneName: 'short'} : {})});
    return ms => format.format(new Date(ms));
}

function base(zone, locale = 'en') {
    return {animation: false, aria: {enabled: true}, color: ['#257265', '#bc7334', '#3a6fa0'],
        grid: {left: 56, right: 60, top: 50, bottom: 65},
        tooltip: {trigger: 'axis', renderMode: 'richText'},
        xAxis: {type: 'time', axisLabel: {formatter: ms => timeFormat(zone, false, locale)(ms).replace(', ', '\n'), hideOverlap: true, fontSize: 11},
            axisPointer: {label: {formatter: p => timeFormat(zone, true, locale)(p.value)}}},
        yAxis: {type: 'value'}, dataZoom: [{type: 'slider', height: 18, bottom: 8}],
        legend: {top: 0}};
}

/** Original logger spans are a separate layer, never minute samples or cumulative additions. */
export function hardwareIntervals(series, zone, name = null, yAxisIndex = 0, {locale = 'en', labels = {}} = {}) {
    name ??= weatherText(locale, labels).logger;
    return {id: `hardware-${name}`, name, type: 'custom', yAxisIndex,
        dimensions: ['start', 'end', 'value'], encode: {x: [0, 1], y: 2},
        data: (series?.fallback || []).filter(p => typeof p.value === 'number')
            .map(p => [p.start * 1000, p.end * 1000, p.value]),
        tooltip: {trigger: 'item', renderMode: 'richText', formatter: p =>
            `${name}\n${timeFormat(zone, true, locale)(p.value[0])} – ${timeFormat(zone, true, locale)(p.value[1])}\n${p.value[2]} ${series?.unitLabel || ''}`},
        renderItem: (params, api) => {
            const start = api.coord([api.value(0), api.value(2)]), end = api.coord([api.value(1), api.value(2)]);
            const left = Math.max(start[0], params.coordSys.x), right = Math.min(end[0], params.coordSys.x + params.coordSys.width);
            if (left > right) return;
            const style = {stroke: api.visual('color'), lineWidth: 3};
            return {type: 'group', children: [
                {type: 'line', shape: {x1: left, y1: start[1], x2: right, y2: end[1]}, style: {...style, lineDash: [5, 3]}},
                {type: 'line', shape: {x1: left, y1: start[1] - 4, x2: left, y2: start[1] + 4}, style},
                {type: 'line', shape: {x1: right, y1: end[1] - 4, x2: right, y2: end[1] + 4}, style},
            ]};
        }};
}

export function temperatureHumidity(data, zone, {locale = 'en', labels = {}} = {}) {
    const text = weatherText(locale, labels);
    return {...base(zone, locale),
        dataset: [
            {id: 'temperature', dimensions: ['time', 'temperature'], source: pairs(data.temperature24h)},
            {id: 'humidity', dimensions: ['time', 'humidity'], source: pairs(data.humidity24h)},
        ],
        yAxis: [{type: 'value', name: data.temperature24h?.unitLabel || '', scale: true}, {type: 'value', name: data.humidity24h?.unitLabel || '', min: 0, max: 100}],
        series: [
            {id: 'temperature', name: text.temperature, type: 'line', datasetId: 'temperature', encode: {x: 'time', y: 'temperature'}, showSymbol: false, connectNulls: false},
            {id: 'humidity', name: text.humidity, type: 'line', datasetId: 'humidity', encode: {x: 'time', y: 'humidity'}, yAxisIndex: 1, showSymbol: false, connectNulls: false},
            hardwareIntervals(data.temperature24h, zone, `${text.temperature} · ${text.logger}`, 0, {locale}),
            hardwareIntervals(data.humidity24h, zone, `${text.humidity} · ${text.logger}`, 1, {locale}),
        ]};
}

export function dailyRain(series, zone, {locale = 'en', labels = {}} = {}) {
    const text = weatherText(locale, labels);
    const day = new Intl.DateTimeFormat(locale, {timeZone: zone, day: '2-digit', month: '2-digit'});
    return {...base(zone, locale), xAxis: {type: 'category', data: (series?.points || []).map(p => day.format(new Date(p.start * 1000)))},
        yAxis: {type: 'value', name: series?.unitLabel || '', min: 0},
        series: [{id: 'rain', name: text.rain, type: 'bar', data: (series?.points || []).map(p => p.value), barMaxWidth: 42}]};
}

export function rainAccumulation(series, zone, {locale = 'en', labels = {}} = {}) {
    const text = weatherText(locale, labels);
    return {...base(zone, locale), yAxis: [{type: 'value', name: `${series?.unitLabel || ''} ${text.cumulative}`.trim(), min: 0}, {type: 'value', name: `${series?.unitLabel || ''} / ${text.loggerInterval}`.trim(), min: 0}],
        series: [{id: 'cumulative', name: text.cumulative, type: 'line', step: 'end', showSymbol: false,
            connectNulls: false, data: cumulative(series)}, hardwareIntervals(series, zone, text.loggerInterval, 1, {locale})]};
}

export function monthlyComparison(report, zone, {locale = 'en', labels = {}} = {}) {
    const text = weatherText(locale, labels);
    const year = new Intl.DateTimeFormat(locale, {timeZone: zone, year: 'numeric'});
    const periods = report?.periods?.points || [];
    return {...base(zone, locale), xAxis: {type: 'category', data: periods.map(p => year.format(new Date(p.start * 1000)))},
        yAxis: {type: 'value', name: report?.periods?.unitLabel || '', min: 0},
        series: [{id: 'comparison', name: text.comparison, type: 'bar', data: periods.map(p => p.value), barMaxWidth: 42}]};
}
