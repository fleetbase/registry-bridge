import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import ApplicationRoute from '@fleetbase/registry-bridge-engine/routes/application';

module('Unit | Route | application', function (hooks) {
    setupTest(hooks);

    // The engine's application route is not re-exported through `app/` (that would
    // shadow the host console's own application route), so register it directly.
    hooks.beforeEach(function () {
        this.owner.register('route:application', ApplicationRoute);
    });

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:application');
        assert.ok(route);
    });
});
