import test from 'node:test';
import assert from 'node:assert/strict';
import {pairs, cumulative, dailyRain, temperatureHumidity, hardwareIntervals, rainAccumulation} from '../public/assets/chart-recipes.js';

test('ECharts gets milliseconds, unrounded numbers and real nulls', () => {
    assert.deepEqual(pairs({points: [{end: 10, value: 1.2345}, {end: 20, value: null}, {end: 30, value: 0}]}),
        [[10000, 1.2345], [20000, null], [30000, 0]]);
});
test('cumulative rain stops at the first missing or incompletely measured interval', () => {
    const series = {points: [
        {end: 10, value: 0, coverage: 1}, {end: 20, value: 2.5, coverage: 1},
        {end: 30, value: 0, coverage: 0.95}, {end: 40, value: 3, coverage: 1},
    ]};
    assert.deepEqual(cumulative(series), [[10000, 0], [20000, 2.5], [30000, null], [40000, null]]);
    assert.deepEqual(cumulative({points: [{end: 1, value: 0, coverage: null}]}), [[1000, null]]);
});
test('daily labels refer to interval start in station timezone across DST', () => {
    const points = [{start: Date.parse('2026-03-28T23:00:00Z') / 1000, end: Date.parse('2026-03-29T22:00:00Z') / 1000, value: 0}];
    const option = dailyRain({points}, 'Europe/Berlin');
    assert.deepEqual(option.xAxis.data, ['29.03.']);
    assert.deepEqual(option.series[0].data, [0]);
    assert.equal(temperatureHumidity({}, 'Europe/Berlin').series[0].connectNulls, false);
});

test('coarse logger data retains both boundaries and never fills cumulative minute gaps', () => {
    const series = {unit: 'mm', points: [{start: 0, end: 60, value: null, coverage: 0}],
        fallback: [{start: 0, end: 300, value: 2, coverage: 1}]};
    const option = hardwareIntervals(series, 'UTC');
    assert.deepEqual(option.data, [[0, 300000, 2]]);
    assert.deepEqual(pairs(series), [[60000, null]]);
    assert.deepEqual(cumulative(series), [[60000, null]]);
    const rain = rainAccumulation(series, 'UTC');
    assert.equal(rain.series[1].yAxisIndex, 1);
    assert.deepEqual(rain.series[1].data, [[0, 300000, 2]]);
    const rendered = option.renderItem({coordSys: {x: 0, width: 400000}}, {
        value: index => option.data[0][index], coord: value => value, visual: () => '#000',
    });
    assert.equal(rendered.children[0].shape.x2, 300000);
});
