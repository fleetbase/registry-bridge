import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Controller | explore/index', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:explore/index');
        assert.ok(controller);
    });

    test('registryHost names the configured service, never a default host', function (assert) {
        const controller = this.owner.lookup('controller:explore/index');
        const registryContext = this.owner.lookup('service:registry-context');

        registryContext.context = {};
        assert.strictEqual(controller.registryHost, 'The extensions registry', 'unknown until the context arrives');

        registryContext.context = { service_url: 'https://registry.example.test' };
        assert.strictEqual(controller.registryHost, 'https://registry.example.test');
    });
});
