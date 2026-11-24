import { module, test } from 'qunit';
import { setupTest, StubFetchService } from 'dummy/tests/helpers';

module('Unit | Service | extension-installer', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', StubFetchService);
        this.service = this.owner.lookup('service:extension-installer');
        this.fetch = this.owner.lookup('service:fetch');
        this.extension = { id: 'ext_1', public_id: 'ext_1', name: 'Pallet WMS', latest_version: '0.9.1' };
    });

    test('request queues the job with the release, except for an uninstall', async function (assert) {
        await this.service.request('install', this.extension, '0.9.1');
        await this.service.request('uninstall', this.extension, '0.9.1');

        assert.deepEqual(
            this.fetch.calls.map((call) => [call.path, call.method, call.data]),
            [
                ['installer/install', 'POST', { extension: 'ext_1', version: '0.9.1' }],
                ['installer/uninstall', 'POST', { extension: 'ext_1' }],
            ]
        );
    });

    test('an unknown action is refused', function (assert) {
        assert.throws(() => this.service.request('reinstall', this.extension), /Unknown installer action/);
    });
});
