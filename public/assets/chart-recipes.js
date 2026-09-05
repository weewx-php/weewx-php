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

export function timeFormat(zone, detailed = false) {
    const format = new Intl.DateTimeFormat('de-DE', {timeZone: zone, day: '2-digit', month: '2-digit',
        hour: '2-digit', minute: '2-digit', ...(detailed ? {year: 'numeric', timeZoneName: 'short'} : {})});
    return ms => format.format(new Date(ms));
}

function base(zone) {
    return {animation: false, aria: {enabled: true}, color: ['#257265', '#bc7334', '#3a6fa0'],
        grid: {left: 56, right: 60, top: 50, bottom: 65},
        tooltip: {trigger: 'axis', renderMode: 'richText'},
        xAxis: {type: 'time', axisLabel: {formatter: ms => timeFormat(zone)(ms).replace(', ', '\n'), hideOverlap: true, fontSize: 11},
            axisPointer: {label: {formatter: p => timeFormat(zone, true)(p.value)}}},
        yAxis: {type: 'value'}, dataZoom: [{type: 'slider', height: 18, bottom: 8}],
        legend: {top: 0}};
}

export function temperatureHumidity(data, zone) {
    return {...base(zone),
        dataset: [
            {id: 'temperature', dimensions: ['time', 'temperature'], source: pairs(data.temperature24h)},
            {id: 'humidity', dimensions: ['time', 'humidity'], source: pairs(data.humidity24h)},
        ],
        yAxis: [{type: 'value', name: '°C', scale: true}, {type: 'value', name: '%', min: 0, max: 100}],
        series: [
            {id: 'temperature', name: 'Temperatur', type: 'line', datasetId: 'temperature', encode: {x: 'time', y: 'temperature'}, showSymbol: false, connectNulls: false},
            {id: 'humidity', name: 'Luftfeuchte', type: 'line', datasetId: 'humidity', encode: {x: 'time', y: 'humidity'}, yAxisIndex: 1, showSymbol: false, connectNulls: false},
        ]};
}

export function dailyRain(series, zone) {
    const day = new Intl.DateTimeFormat('de-DE', {timeZone: zone, day: '2-digit', month: '2-digit'});
    return {...base(zone), xAxis: {type: 'category', data: (series?.points || []).map(p => day.format(new Date(p.start * 1000)))},
        yAxis: {type: 'value', name: 'mm', min: 0},
        series: [{id: 'rain', name: 'Niederschlag', type: 'bar', data: (series?.points || []).map(p => p.value), barMaxWidth: 42}]};
}

export function rainAccumulation(series, zone) {
    return {...base(zone), yAxis: {type: 'value', name: 'mm', min: 0},
        series: [{id: 'cumulative', name: 'Kumuliert', type: 'line', step: 'end', showSymbol: false,
            connectNulls: false, data: cumulative(series)}]};
}

export function monthlyComparison(report, zone) {
    const year = new Intl.DateTimeFormat('de-DE', {timeZone: zone, year: 'numeric'});
    const periods = report?.periods?.points || [];
    return {...base(zone), xAxis: {type: 'category', data: periods.map(p => year.format(new Date(p.start * 1000)))},
        yAxis: {type: 'value', name: 'mm', min: 0},
        series: [{id: 'comparison', name: 'Monat bis Referenzzeit', type: 'bar', data: periods.map(p => p.value), barMaxWidth: 42}]};
}
