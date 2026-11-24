import { module, test } from 'qunit';
import { setupTest, StubFetchService } from 'dummy/tests/helpers';
import Service from '@ember/service';

class HostRouterStub extends Service {
    transitions = [];
    transitionTo(...args) {
        this.transitions.push(args);
    }
}

module('Unit | Service | extension-panel', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', StubFetchService);
        this.owner.register('service:host-router', HostRouterStub);
        this.panel = this.owner.lookup('service:extension-panel');
        this.router = this.owner.lookup('service:host-router');
    });

    test('open goes to the extension page by public id', function (assert) {
        this.panel.open({ id: 'ext_1', public_id: 'ext_1', name: 'Pallet' });

        assert.deepEqual(this.router.transitions, [['console.extensions.extension', 'ext_1']]);
        assert.strictEqual(this.panel.extension.name, 'Pallet');
    });

    test('openById loads the record and opens its page; a failure opens nothing', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');
        fetch.responses['registry-extensions/ext_2'] = { id: 'ext_2', public_id: 'ext_2', name: 'Loconav' };

        const extension = await this.panel.openById('ext_2');
        assert.strictEqual(extension.name, 'Loconav');
        assert.deepEqual(this.router.transitions, [['console.extensions.extension', 'ext_2']]);

        fetch.responses['registry-extensions/ext_3'] = new Error('Extension not found.');
        assert.strictEqual(await this.panel.openById('ext_3'), null);
        assert.strictEqual(this.router.transitions.length, 1);
    });
});
