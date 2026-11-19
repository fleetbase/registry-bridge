import { module, test } from 'qunit';
import { setupRenderingTest, StubFetchService, NotificationsStub, AbilitiesStub } from 'dummy/tests/helpers';
import { render, select } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | cell/auto-update-policy', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', StubFetchService);
        this.owner.register('service:notifications', NotificationsStub);
        this.owner.register('service:abilities', AbilitiesStub);
        this.fetch = this.owner.lookup('service:fetch');
        this.notifications = this.owner.lookup('service:notifications');
    });

    test('it shows the policy and saves a new one', async function (assert) {
        this.fetch.response = (data) => ({ extension: 'ext_1', auto_update: data.policy });
        this.set('row', { id: 'ext_1', name: 'Fleet Insights', auto_update: 'none', auto_update_last: null });

        await render(hbs`<Cell::AutoUpdatePolicy @row={{this.row}} />`);
        assert.dom('[data-test-auto-update-select]').hasValue('none');
        assert.dom('[data-test-auto-update-select] option').exists({ count: 3 });
        assert.dom('[data-test-auto-update-failed]').doesNotExist();

        await select('[data-test-auto-update-select]', 'minor');

        assert.deepEqual(this.fetch.calls[0], { path: 'registry-extensions/ext_1/auto-update', method: 'POST', data: { policy: 'minor' }, options: { namespace: '~registry/v1' } });
        assert.dom('[data-test-auto-update-select]').hasValue('minor');
        assert.strictEqual(this.notifications.messages[0].type, 'success');
        assert.strictEqual(this.notifications.messages[0].message, 'Auto-update for Fleet Insights set to Minor releases.');
    });

    test('a refused save puts the previous policy back', async function (assert) {
        this.fetch.response = new Error('nope');
        this.set('row', { id: 'ext_1', name: 'Fleet Insights', auto_update: 'patch' });

        await render(hbs`<Cell::AutoUpdatePolicy @row={{this.row}} />`);
        await select('[data-test-auto-update-select]', 'minor');

        assert.dom('[data-test-auto-update-select]').hasValue('patch');
        assert.ok(
            this.notifications.messages.some((message) => message.type !== 'success'),
            'the error is shown'
        );
    });

    test('it flags a failed automatic update and is read-only without the install permission', async function (assert) {
        this.owner.lookup('service:abilities').denied.add('registry-bridge install extension');
        this.set('row', { id: 'ext_1', name: 'Fleet Insights', auto_update: 'minor', auto_update_last: { version: '1.2.0', status: 'failed' } });

        await render(hbs`<Cell::AutoUpdatePolicy @row={{this.row}} />`);

        assert.dom('[data-test-auto-update-select]').isDisabled();
        assert.dom('[data-test-auto-update-failed]').hasAttribute('title', 'Last automatic update to v1.2.0 failed; it is not retried until a newer release appears.');
    });

    test('core services and rows without a policy show nothing', async function (assert) {
        this.set('row', { id: 'fleet-ops', name: 'FleetOps', core_extension: true, auto_update: 'none' });
        await render(hbs`<Cell::AutoUpdatePolicy @row={{this.row}} />`);
        assert.dom('[data-test-auto-update]').doesNotExist();

        this.set('row', { id: 'ext_2', name: 'Older payload' });
        assert.dom('[data-test-auto-update]').doesNotExist();
    });
});
