import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

const script = readFileSync(new URL('../public/admin/admin.js', import.meta.url), 'utf8');

function element() {
    const attributes = new Map(), classes = new Set(), listeners = new Map();
    return {
        dataset: {},
        classList: {
            add: value => classes.add(value),
            remove: value => classes.delete(value),
            contains: value => classes.has(value),
            toggle: (value, force) => force ? classes.add(value) : classes.delete(value),
        },
        setAttribute: (name, value) => attributes.set(name, value),
        removeAttribute: name => attributes.delete(name),
        hasAttribute: name => attributes.has(name),
        querySelector: () => null,
        querySelectorAll: () => [],
        matches: () => false,
        addEventListener(type, callback) {
            if (!listeners.has(type)) listeners.set(type, []);
            listeners.get(type).push(callback);
        },
        fire(type, properties = {}) {
            const event = {target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...properties};
            for (const callback of listeners.get(type) || []) callback(event);
            return event;
        },
    };
}

function form(elements, draft = false) {
    const form = element(), status = element(), actions = element();
    actions.append = () => {};
    form.elements = elements;
    form.method = 'post';
    if (draft) form.setAttribute('data-unsaved-draft', '');
    form.querySelector = selector => {
        if (selector === '[data-form-status]') return status;
        if (selector === '.form-actions') return actions;
        if (selector === 'input:not([type="hidden"]), select, textarea') return elements.find(input => input.type !== 'hidden') || null;
        return null;
    };
    return form;
}

function page(...forms) {
    const document = element(), window = element(), timers = new Map();
    let timerId = 0;
    document.documentElement = element();
    document.body = element();
    document.body.dataset.unsaved = 'Unsaved changes';
    document.getElementById = () => null;
    document.createElement = element;
    document.querySelectorAll = selector => selector === 'form[data-edit-form]' ? forms : [];
    runInNewContext(script, {
        document, window,
        setTimeout: (callback, delay) => { timers.set(++timerId, {callback, delay}); return timerId; },
        clearTimeout: id => timers.delete(id),
    });
    return {
        document,
        unload: () => window.fire('beforeunload').defaultPrevented,
        submit: (target, submitter = element()) => document.fire('submit', {target, submitter}),
        flush() {
            for (const [id, timer] of [...timers]) {
                if (timer.delay === 0) { timers.delete(id); timer.callback(); }
            }
        },
    };
}

test('unchanged input events in another form do not block saving settings', () => {
    const retention = {name: 'backup_retention_days', type: 'number', value: '3'};
    const settings = form([retention]);
    const upload = form([{name: 'password', type: 'password', value: ''}]);
    const browser = page(settings, upload);
    upload.fire('input');
    upload.fire('change');
    assert.equal(browser.unload(), false, 'No field value changed');
    retention.value = '4';
    settings.fire('input');
    const button = element();
    button.name = 'action'; button.value = 'settings.save';
    assert.equal(browser.submit(settings, button).defaultPrevented, false);
    assert.equal(button.disabled, undefined, 'The native POST must retain the submitter');
    assert.equal(button.value, 'settings.save');
    assert.equal(browser.unload(), false, 'Saving must not warn about the submitted values');
});

test('restoring text, checkbox and multiple-select values clears the warning', () => {
    const controls = [
        {name: 'name', type: 'text', value: 'Garden'},
        {name: 'enabled', type: 'checkbox', value: 'true', checked: false},
        {name: 'archives', type: 'select-multiple', value: 'garden', selectedOptions: [{value: 'garden'}]},
    ];
    const editor = form(controls), browser = page(editor);
    for (const [index, property, value] of [[0, 'value', 'Roof'], [1, 'checked', true], [2, 'selectedOptions', [{value: 'garden'}, {value: 'roof'}]]]) {
        const original = controls[index][property];
        controls[index][property] = value;
        editor.fire('change');
        assert.equal(browser.unload(), true);
        controls[index][property] = original;
        editor.fire('change');
        assert.equal(browser.unload(), false);
    }
});

test('reset waits for native values and rejected drafts remain unsaved', () => {
    for (const draft of [false, true]) {
        const field = {name: 'name', type: 'text', value: 'Garden'};
        const editor = form([field], draft), browser = page(editor);
        field.value = 'Roof'; editor.fire('input');
        editor.fire('reset');
        field.value = 'Garden';
        browser.flush();
        assert.equal(browser.unload(), draft);
        assert.equal(editor.classList.contains('is-dirty'), draft);
    }
});

test('other edited forms remain protected and cancelling navigation permits retry', () => {
    const first = form([{name: 'name', type: 'text', value: 'Garden'}]);
    const second = form([{name: 'name', type: 'text', value: 'Roof'}]);
    const browser = page(first, second);
    first.elements[0].value = 'Changed'; first.fire('input');
    second.elements[0].value = 'Changed'; second.fire('input');
    const button = element();
    browser.submit(first, button);
    assert.equal(browser.unload(), true, 'Saving one form must protect edits in another');
    browser.flush();
    assert.equal(button.hasAttribute('aria-disabled'), false);
    second.elements[0].value = 'Roof'; second.fire('input');
    assert.equal(browser.submit(first, button).defaultPrevented, false);
    assert.equal(browser.unload(), false);
});

test('programmatic field changes are protected but unnamed search fields are ignored', () => {
    const field = {name: 'latitude', type: 'number', value: '48'};
    const query = {name: '', type: 'text', value: ''};
    const editor = form([field, query]), browser = page(editor);
    query.value = 'Berlin'; editor.fire('input');
    assert.equal(browser.unload(), false);
    field.value = '52';
    assert.equal(browser.unload(), true);
});
