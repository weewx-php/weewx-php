import test from 'node:test';
import assert from 'node:assert/strict';
import {subscribe, feedURL} from '../public/assets/feed-client.js';

test('shared polling, ETags, visibility, retry, timeout and cleanup', async t => {
    const original = {fetch: globalThis.fetch, document: globalThis.document,
        setTimeout: globalThis.setTimeout, clearTimeout: globalThis.clearTimeout};
    const document = new EventTarget();
    document.baseURI = 'https://blog.example/'; document.hidden = false;
    globalThis.document = document;
    const timers = new Map(); let seq = 0;
    globalThis.setTimeout = (fn, delay) => { timers.set(++seq, {fn, delay}); return seq; };
    globalThis.clearTimeout = id => timers.delete(id);
    t.after(() => Object.assign(globalThis, original));
    const flush = () => new Promise(resolve => setImmediate(resolve));
    const fire = delay => {
        const entry = [...timers].find(([, timer]) => timer.delay === delay);
        assert.ok(entry, `Timer ${delay} exists`);
        timers.delete(entry[0]); entry[1].fn();
    };
    const calls = [];
    let result = {version: 1, pollSeconds: 60, data: {temperature: {value: 0}}};
    globalThis.fetch = async (url, options) => {
        calls.push({url, options});
        return result === null ? new Response(null, {status: 304}) : new Response(JSON.stringify(result), {headers: {ETag: '"first"'}});
    };
    const seen = [], seenAgain = [];
    const a = subscribe('https://station.example/api/v1.php?feed=live', (data, error) => seen.push({data, error}));
    const b = subscribe('https://station.example/api/v1.php?feed=live', data => seenAgain.push(data));
    await flush();
    assert.equal(calls.length, 1);
    assert.equal(seen[0].data.data.temperature.value, 0);
    assert.equal(seenAgain.length, 1);
    assert.equal(calls[0].options.credentials, 'omit');
    result = null; fire(60000); await flush();
    assert.equal(calls[1].options.headers['If-None-Match'], '"first"');
    assert.equal(seen[1].data, seen[0].data);
    assert.equal(seen[1].error, null);
    document.hidden = true; document.dispatchEvent(new Event('visibilitychange'));
    assert.equal(timers.size, 0);
    document.hidden = false; document.dispatchEvent(new Event('visibilitychange')); await flush();
    assert.equal(calls.length, 3);
    a(); assert.ok(timers.size > 0); b(); assert.equal(timers.size, 0);

    result = {version: 1, pollSeconds: 60, data: {temperature: {value: 1}}};
    const fresh = subscribe('https://station.example/api/v1.php?feed=live', () => {});
    a(); // A removed/reconnected custom element may dispose the old subscription twice.
    const shared = subscribe('https://station.example/api/v1.php?feed=live', () => {});
    await flush();
    assert.equal(calls.length, 4, 'Old cleanup must not remove a newer shared subscription');
    fresh(); shared(); assert.equal(timers.size, 0);

    globalThis.fetch = async () => new Response('failure', {status: 503});
    const failures = [];
    const c = subscribe('/api?feed=outside', (data, error) => failures.push({data, error}));
    await flush();
    assert.equal(failures[0].data, null);
    assert.match(failures[0].error.message, /503/);
    assert.ok([...timers.values()].some(timer => timer.delay === 30000));
    c(); assert.equal(timers.size, 0);

    globalThis.fetch = (url, options) => new Promise((resolve, reject) => {
        options.signal.addEventListener('abort', () => reject(new Error('timeout')));
    });
    const errors = [];
    const d = subscribe('/slow', (data, error) => errors.push(error));
    await flush(); fire(8000); await flush();
    assert.equal(errors[0].message, 'timeout');
    d(); assert.equal(timers.size, 0);
    assert.throws(() => feedURL('javascript:alert(1)'), /Invalid/);
    assert.throws(() => feedURL('https://secret:password@station.example/'), /Invalid/);
    assert.equal(feedURL('/api#fragment'), 'https://blog.example/api');
});
