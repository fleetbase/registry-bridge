import { module, test } from 'qunit';
import { MONOGRAM_COLORS, buttonState, monogram, progressOf, verdictOf } from '@fleetbase/registry-bridge-engine/utils/store-model';

const listing = (attrs = {}, meta = {}) => ({ source: 'registry-service', is_installable: true, ...attrs, meta: { source: 'registry-service', ...meta } });
const verdict = (code, reason = 'ok') => ({ compatibility: { verdict: code, reason } });

module('Unit | Utility | store-model', function () {
    test('the monogram is stable per name and uses the palette', function (assert) {
        const a = monogram({ name: 'LocoNav' });
        assert.strictEqual(a.letter, 'L');
        assert.true(MONOGRAM_COLORS.includes(a.color));
        assert.deepEqual(monogram({ name: 'loconav' }), a, 'case does not change the colour');
        assert.strictEqual(monogram({}).letter, '?');
    });

    test('progress accepts a stage and percent or a job record', function (assert) {
        assert.strictEqual(progressOf(null), null);
        assert.deepEqual(progressOf({ stage: 'installing', percent: 62.4 }), { stage: 'installing', percent: 62, kind: 'install' });
        assert.deepEqual(progressOf({ step: 1, steps: 4 }), { stage: 'downloading', percent: 25, kind: 'install' });
        assert.strictEqual(progressOf({ percent: 95 }).stage, 'finishing');
        assert.strictEqual(progressOf({ percent: 140 }).percent, 100);
        assert.deepEqual(progressOf({ action: 'update', phase: 'install', percent: 55, stage: 'composer' }), { stage: 'installing', percent: 55, kind: 'update' });
        assert.strictEqual(progressOf({ action: 'install', phase: 'finish', percent: 80 }).stage, 'finishing');
    });

    test('the button follows the listing state', function (assert) {
        assert.strictEqual(buttonState(listing({ payment_required: false })).kind, 'get');
        assert.strictEqual(buttonState(listing({ payment_required: true, price: 900 }), {}).kind, 'buy');
        assert.strictEqual(buttonState(listing({ is_installed: true })).kind, 'open');
        assert.strictEqual(buttonState(listing({ is_installed: true, update_available: true, latest_version: '1.3.0' })).kind, 'update');
        assert.strictEqual(buttonState(listing({}, verdict('needs_update', 'console_too_old'))).kind, 'blocked');
        assert.strictEqual(buttonState(listing({ is_installable: false }, { install_blocked_code: 'hosting', ...verdict('unavailable', 'hosting') })).kind, 'unavailable');
    });

    test('a running install turns any button into progress, an uninstall does not', function (assert) {
        const record = listing({ payment_required: false });
        assert.deepEqual(buttonState(record, { progress: { stage: 'downloading', percent: 18 } }), { stage: 'downloading', percent: 18, job: 'install', kind: 'progress', primary: true });
        assert.strictEqual(buttonState(listing({ is_installed: true }), { progress: { percent: 40, kind: 'uninstall' } }).kind, 'open');
    });

    test('roles without the permission get a disabled button', function (assert) {
        assert.strictEqual(buttonState(listing({ payment_required: false }), { canInstall: false }).kind, 'unauthorized');
        assert.true(buttonState(listing({ payment_required: false }), { canInstall: false }).disabled);
    });

    test('verdictOf ignores unknown codes', function (assert) {
        assert.strictEqual(verdictOf(listing({}, verdict('ready'))), 'ready');
        assert.strictEqual(verdictOf(listing({}, verdict('maybe'))), null);
        assert.strictEqual(verdictOf({}), null);
    });
});
