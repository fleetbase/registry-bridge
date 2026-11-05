import { module, test } from 'qunit';
import { setupTest, AbilitiesStub, CurrentUserStub, ModalsManagerStub, NotificationsStub, SocketStub, StubFetchService } from 'dummy/tests/helpers';
import { installerButtons } from '@fleetbase/registry-bridge-engine/services/extension-installer';

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

module('Unit | Service | extension-installer', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:modals-manager', ModalsManagerStub);
        this.owner.register('service:abilities', AbilitiesStub);
        this.owner.register('service:current-user', CurrentUserStub);
        this.owner.register('service:socket', SocketStub);
        this.owner.register('service:fetch', StubFetchService);
        this.owner.register('service:notifications', NotificationsStub);
        this.service = this.owner.lookup('service:extension-installer');
        this.modals = this.owner.lookup('service:modals-manager');
        this.fetch = this.owner.lookup('service:fetch');
        this.socket = this.owner.lookup('service:socket');
        this.reloads = 0;
        this.service.reload = () => this.reloads++;
        this.service.reloadAfter = 1;
        this.extension = {
            id: 'ext_1',
            public_id: 'ext_1',
            slug: 'pallet',
            name: 'Pallet WMS',
            latest_version: '0.9.1',
            meta: { package_names: { npm: '@acme/pallet-engine', composer: 'acme/pallet-api' } },
        };
    });

    test('the footer buttons follow the phase', function (assert) {
        const intl = { t: (key) => key };

        assert.strictEqual(installerButtons(intl, 'install', 'confirm').acceptButtonText, 'registry-bridge.install.confirm.accept');
        assert.strictEqual(installerButtons(intl, 'update', 'confirm').acceptButtonText, 'registry-bridge.update.confirm.accept');
        assert.strictEqual(installerButtons(intl, 'uninstall', 'confirm').acceptButtonScheme, 'danger');
        assert.true(installerButtons(intl, 'install', 'progress').hideDeclineButton);
        assert.deepEqual(
            [installerButtons(intl, 'install', 'success').acceptButtonText, installerButtons(intl, 'install', 'success').declineButtonText],
            ['registry-bridge.install.success.reload-now', 'registry-bridge.install.success.stay']
        );
        assert.strictEqual(installerButtons(intl, 'uninstall', 'failure').acceptButtonText, 'registry-bridge.uninstall.failed.retry');
    });

    test('install confirms, queues the job with the release and finishes with a reload countdown', async function (assert) {
        this.fetch.responses['installer/install'] = { status: 'queued', job: { id: 'j1', status: 'queued' } };
        this.fetch.responses['installer/jobs/j1'] = { job: { id: 'j1', status: 'completed' } };
        let completed = null;

        this.service.install(this.extension, { onCompleted: (job) => (completed = job) });
        assert.strictEqual(this.modals.current.component, 'modals/extension-installer');
        assert.strictEqual(this.modals.options.phase, 'confirm');
        assert.strictEqual(this.modals.options.version, '0.9.1');
        assert.deepEqual(
            this.modals.options.steps.map((step) => step.key),
            ['server', 'engine', 'build']
        );

        const running = this.modals.confirm();
        await settle();
        assert.deepEqual(this.fetch.calls[0], { path: 'installer/install', method: 'POST', data: { extension: 'ext_1', version: '0.9.1' }, options: { namespace: '~registry/v1' } });
        assert.strictEqual(this.modals.options.phase, 'progress');
        assert.true(this.modals.options.hideDeclineButton, 'no Cancel while the job runs');
        assert.ok(this.socket.listeners['install.company_1.ext_1'], 'follows the install channel');

        this.socket.emit('install.company_1.ext_1', { job: 'j1', status: 'completed' });
        await running;

        assert.strictEqual(this.modals.options.phase, 'success');
        assert.strictEqual(this.modals.options.acceptButtonText, 'Reload now');
        assert.false(this.modals.options.isLoading, 'Reload now and Wait can be pressed');
        assert.strictEqual(completed.status, 'completed');

        await this.modals.confirm();
        assert.strictEqual(this.reloads, 1, 'Reload now reloads');
    });

    test('uninstall needs the typed name before it runs', async function (assert) {
        this.service.uninstall({ ...this.extension, installed_version: '0.9.0' });
        assert.strictEqual(this.modals.options.version, '0.9.0');

        await this.modals.confirm();
        assert.strictEqual(this.fetch.calls.length, 0, 'nothing is sent without the name');

        this.fetch.responses['installer/uninstall'] = { status: 'ok' };
        this.modals.setOption('confirmName', 'Pallet WMS');
        await this.modals.confirm();
        assert.deepEqual(this.fetch.calls[0].data, { extension: 'ext_1' }, 'uninstall sends no version');
        assert.ok(this.socket.listeners['uninstall.company_1.ext_1']);
    });

    test('Wait, stay here cancels the reload and closes', async function (assert) {
        this.fetch.responses['installer/update'] = { status: 'ok', job: { id: 'j2', status: 'completed' } };
        this.service.update(this.extension, { version: '1.0.0' });
        await this.modals.confirm();
        assert.strictEqual(this.fetch.calls[0].data.version, '1.0.0');
        assert.strictEqual(this.modals.options.phase, 'success');

        const options = this.modals.options;
        await this.modals.decline();
        assert.true(options.reloadCancelled);
        assert.strictEqual(this.modals.closed, 1);
        await new Promise((resolve) => setTimeout(resolve, 1100));
        assert.strictEqual(this.reloads, 0);
    });

    test('a failed job shows Retry, and retrying sends the request again', async function (assert) {
        this.fetch.responses['installer/install'] = { job: { id: 'j3', status: 'failed', step: 'server.install', message: 'composer failed' } };
        this.service.install(this.extension);
        await this.modals.confirm();

        assert.strictEqual(this.modals.options.phase, 'failure');
        assert.strictEqual(this.modals.options.acceptButtonText, 'Retry install');
        assert.strictEqual(this.modals.options.installerError.message, 'composer failed');

        await this.modals.confirm();
        assert.strictEqual(this.fetch.calls.filter((call) => call.path === 'installer/install').length, 2);
    });

    test('install is disabled without the permission', function (assert) {
        this.owner.lookup('service:abilities').denied.add('registry-bridge install extension');
        this.service.install(this.extension);
        assert.true(this.modals.options.acceptButtonDisabled);
    });
});
