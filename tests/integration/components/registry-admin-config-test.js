import { module, test } from 'qunit';
import { setupRenderingTest, StubFetchService } from 'dummy/tests/helpers';
import { render, click, fillIn, findAll, waitFor } from '@ember/test-helpers';
import Service from '@ember/service';
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

const UNREGISTERED = {
    service_url: 'https://registry.example.test',
    publisher_url: 'https://registry.example.test',
    instance_kind: 'self_hosted',
    instance: { registered: false, id: null, name: null, organization: null, token_hint: null, registered_at: null },
};

const REGISTERED = {
    ...UNREGISTERED,
    instance: {
        registered: true,
        id: 'inst_rsmdtgevid',
        name: 'Acme Fleet (self-hosted)',
        organization: { id: 'org_qqymqcyvgv', slug: 'registry-demo-buyer', name: 'Registry Demo Buyer' },
        token_hint: 'flbi_…0000',
        registered_at: '2026-10-22T07:09:47+00:00',
    },
};

module('Integration | Component | registry-admin-config', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', StubFetchService);
        this.fetch = this.owner.lookup('service:fetch');
        this.fetch.responses['registry-extensions/config'] = UNREGISTERED;
    });

    test('it loads the saved service URL with the actions', async function (assert) {
        await render(TEMPLATE);

        assert.strictEqual(this.fetch.calls[0].path, 'registry-extensions/config', 'loads the saved config');
        assert.dom('[data-test-service-url]').hasValue('https://registry.example.test');
        assert.dom(this.element).doesNotContainText('Registry Token', 'no v1 registry token any more');
        assert.ok(buttonLabelled('Test Connection'), 'test connection action renders');
        assert.ok(buttonLabelled('Save Changes'), 'save action renders');
    });

    test('it saves the service URL only', async function (assert) {
        await render(TEMPLATE);
        await fillIn('[data-test-service-url]', 'https://registry.other.test');
        await click(buttonLabelled('Save Changes'));

        const call = this.fetch.calls.find((call) => call.path === 'registry-extensions/config' && call.method === 'POST');
        assert.deepEqual(call.data, { service_url: 'https://registry.other.test' });
    });

    test('it reports a failed connection', async function (assert) {
        this.fetch.responses['registry-extensions/config/test'] = {
            ok: false,
            latency_ms: 8,
            status: 503,
            extension_count: null,
            host: 'https://registry.example.test',
            error: 'Registry responded with HTTP 503.',
        };

        await render(TEMPLATE);
        await click(buttonLabelled('Test Connection'));
        await waitFor('[data-test-connection-result]');

        assert.dom('[data-test-connection-result]').containsText('Unable to connect to the registry');
        assert.dom('[data-test-connection-result]').containsText('Registry responded with HTTP 503.');
    });

    test('it shows the registry service registration with a masked token only', async function (assert) {
        this.fetch.responses['registry-extensions/config'] = REGISTERED;

        await render(TEMPLATE);

        const values = [...this.element.querySelectorAll('input')].map((input) => input.value);
        assert.true(
            values.some((value) => value === 'https://registry.example.test'),
            'service URL is editable'
        );
        assert.dom('[data-test-registration-status]').containsText('Acme Fleet (self-hosted)');
        assert.dom('[data-test-registration-status]').containsText('Registry Demo Buyer');
        assert.dom('[data-test-token-hint]').hasText('flbi_…0000');
        assert.ok(buttonLabelled('Unregister'), 'unregister action renders');
        assert.notOk(buttonLabelled('Register this instance'), 'no registration form once registered');
    });

    test('it registers the instance with a code', async function (assert) {
        this.fetch.responses['registry-extensions/config'] = UNREGISTERED;
        this.fetch.responses['registry-extensions/instance/register'] = REGISTERED;

        await render(TEMPLATE);
        assert.true(buttonLabelled('Register this instance').disabled, 'register needs a code');

        await fillIn('input[placeholder="XXXX-XXXX-XXXX-XXXX"]', ' w9qa-sc8r-37jt-9pa8 ');
        await click(buttonLabelled('Register this instance'));
        await waitFor('[data-test-registration-status]');

        const call = this.fetch.calls.find((call) => call.path === 'registry-extensions/instance/register');
        assert.strictEqual(call.method, 'POST');
        assert.deepEqual(
            call.data,
            { code: 'w9qa-sc8r-37jt-9pa8', service_url: 'https://registry.example.test', host: window.location.origin },
            'sends the trimmed code, service URL and the console origin'
        );
        assert.dom('[data-test-token-hint]').hasText('flbi_…0000');
    });

    test('it unregisters after confirmation', async function (assert) {
        this.fetch.responses['registry-extensions/config'] = REGISTERED;
        this.fetch.responses['registry-extensions/instance'] = UNREGISTERED;
        this.owner.register(
            'service:modals-manager',
            class extends Service {
                confirm(options) {
                    return options.confirm({ startLoading() {}, done() {} });
                }
            }
        );

        await render(TEMPLATE);
        await click(buttonLabelled('Unregister'));
        await waitFor('input[placeholder="XXXX-XXXX-XXXX-XXXX"]');

        const call = this.fetch.calls.find((call) => call.path === 'registry-extensions/instance');
        assert.strictEqual(call.method, 'DELETE');
        assert.notOk(this.element.querySelector('[data-test-registration-status]'), 'registration status is gone');
    });

    test('it tests the registry service and reports the instance token check', async function (assert) {
        this.fetch.responses['registry-extensions/config'] = REGISTERED;
        this.fetch.responses['registry-extensions/config/test'] = {
            ok: true,
            latency_ms: 12,
            status: 200,
            extension_count: 2,
            host: 'https://registry.example.test',
            error: null,
            api_version: 'v2',
            instance: { ok: true, status: 200, id: 'inst_rsmdtgevid', name: 'Acme Fleet (self-hosted)', organization: 'Registry Demo Buyer', error: null },
        };

        await render(TEMPLATE);
        await click(buttonLabelled('Test Connection'));
        await waitFor('[data-test-instance-check]');

        const testCall = this.fetch.calls.find((call) => call.path === 'registry-extensions/config/test');
        assert.deepEqual(testCall.data, { service_url: 'https://registry.example.test' }, 'tests the service URL');
        assert.dom('[data-test-instance-check]').containsText('Accepted (Acme Fleet (self-hosted), Registry Demo Buyer)');
    });
});
