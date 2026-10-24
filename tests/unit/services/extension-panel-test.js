import { module, test } from 'qunit';
import { setupTest, StubFetchService } from 'dummy/tests/helpers';

module('Unit | Service | extension-panel', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', StubFetchService);
        this.panel = this.owner.lookup('service:extension-panel');
        this.originalUrl = window.location.href;
    });

    hooks.afterEach(function () {
        window.history.replaceState({}, '', this.originalUrl);
    });

    test('opening keeps the extension in the URL and closing removes it', function (assert) {
        this.panel.open({ id: 'ext_1', public_id: 'ext_1', name: 'Pallet' }, { tab: 'versions' });

        assert.true(this.panel.isOpen);
        assert.strictEqual(this.panel.tab, 'versions');
        assert.strictEqual(new URL(window.location.href).searchParams.get('extension_id'), 'ext_1');

        this.panel.close();
        assert.false(this.panel.isOpen);
        assert.strictEqual(new URL(window.location.href).searchParams.get('extension_id'), null);
    });

    test('openById loads the record, and a failure keeps the panel open with the error', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');
        fetch.responses['registry-extensions/ext_2'] = { id: 'ext_2', public_id: 'ext_2', name: 'Loconav' };

        const extension = await this.panel.openById('ext_2');
        assert.strictEqual(extension.name, 'Loconav');
        assert.strictEqual(this.panel.extension.public_id, 'ext_2');

        fetch.responses['registry-extensions/ext_3'] = new Error('Extension not found.');
        await this.panel.openById('ext_3');
        assert.true(this.panel.isOpen);
        assert.strictEqual(this.panel.error.message, 'Extension not found.');
    });
});
