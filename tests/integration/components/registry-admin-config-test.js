import { module, test } from 'qunit';
import { setupRenderingTest, StubFetchService } from 'dummy/tests/helpers';
import { render, click, findAll, waitFor } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

// The component renders its action buttons through ember-wormhole into the
// console's section header, so the test supplies that target.
const TEMPLATE = hbs`
    <div id="next-view-section-subheader-actions"></div>
    <RegistryAdminConfig />
`;

function buttonLabelled(text) {
    return findAll('button').find((button) => button.textContent.includes(text));
}

module('Integration | Component | registry-admin-config', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', StubFetchService);
        this.fetch = this.owner.lookup('service:fetch');
        this.fetch.responses['registry-extensions/config'] = { host: 'https://registry.fleetbase.io', token: 'secret-token' };
    });

    test('it loads the saved registry host and keeps the token masked', async function (assert) {
        await render(TEMPLATE);

        assert.strictEqual(this.fetch.calls[0].path, 'registry-extensions/config', 'loads the saved config');
        const values = [...this.element.querySelectorAll('input')].map((input) => input.value);
        assert.ok(values.includes('https://registry.fleetbase.io'), 'host is editable');
        assert.notOk(values.includes('secret-token'), 'token is not shown in an input');
        assert.dom(this.element).containsText('Reveal token');
        assert.ok(buttonLabelled('Test Connection'), 'test connection action renders');
        assert.ok(buttonLabelled('Save Changes'), 'save action renders');
    });

    test('it tests the connection against the registry and shows the result', async function (assert) {
        this.fetch.responses['registry-extensions/config/test'] = { ok: true, latency_ms: 42, status: 200, extension_count: 7, host: 'https://registry.fleetbase.io', error: null };

        await render(TEMPLATE);
        await click(buttonLabelled('Test Connection'));
        await waitFor('dl');

        const testCall = this.fetch.calls.find((call) => call.path === 'registry-extensions/config/test');
        assert.ok(testCall, 'posts to the test endpoint');
        assert.strictEqual(testCall.method, 'POST');
        assert.deepEqual(testCall.data, { host: 'https://registry.fleetbase.io', token: 'secret-token' }, 'sends the current form values');
        assert.dom(this.element).containsText('Connected to the registry');
        assert.dom(this.element).containsText('42 ms');
        assert.dom(this.element).containsText('7');
    });

    test('it reports a failed connection', async function (assert) {
        this.fetch.responses['registry-extensions/config/test'] = {
            ok: false,
            latency_ms: 8,
            status: 401,
            extension_count: null,
            host: 'https://registry.fleetbase.io',
            error: 'Registry responded with HTTP 401.',
        };

        await render(TEMPLATE);
        await click(buttonLabelled('Test Connection'));
        await waitFor('dl');

        assert.dom(this.element).containsText('Unable to connect to the registry');
        assert.dom(this.element).containsText('Registry responded with HTTP 401.');
    });
});
