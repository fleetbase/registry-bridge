import { module, test } from 'qunit';
import { setupRenderingTest, AbilitiesStub, StubFetchService } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

class InstallerStub extends Service {
    calls = [];
    install(extension, options) {
        this.calls.push(['install', options.version]);
    }
    update(extension, options) {
        this.calls.push(['update', options.version]);
    }
}

const releases = {
    supported: true,
    latest: '0.9.1',
    releases: [
        {
            id: 'rel_3',
            version: '0.9.1',
            channel: 'stable',
            status: 'published',
            size_bytes: 2202009,
            notes: 'Pack verification',
            published_at: '2026-09-12T00:00:00Z',
            is_latest: true,
            is_installed: false,
            is_yanked: false,
        },
        {
            id: 'rel_2',
            version: '0.9.0',
            channel: 'stable',
            status: 'published',
            size_bytes: 2097152,
            notes: 'Wave picking',
            published_at: '2026-08-20T00:00:00Z',
            is_latest: false,
            is_installed: true,
            is_yanked: false,
        },
        {
            id: 'rel_1',
            version: '0.8.4',
            channel: 'stable',
            status: 'yanked',
            size_bytes: 1887436,
            notes: null,
            published_at: '2026-07-02T00:00:00Z',
            is_latest: false,
            is_installed: false,
            is_yanked: true,
        },
    ],
};

module('Integration | Component | extension-panel/versions', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:abilities', AbilitiesStub);
        this.owner.register('service:fetch', StubFetchService);
        this.owner.register('service:extension-installer', InstallerStub);
        this.fetch = this.owner.lookup('service:fetch');
    });

    test('lists releases with their flags and switches an installed extension to one', async function (assert) {
        this.fetch.responses['registry-extensions/ext_1/releases'] = releases;
        this.set('extension', { id: 'ext_1', public_id: 'ext_1', is_installed: true, installed_version: '0.9.0' });

        await render(hbs`<ExtensionPanel::Versions @extension={{this.extension}} />`);

        assert.deepEqual(this.fetch.calls[0].data, { installed_version: '0.9.0' });
        assert.dom('[data-test-release-version]').exists({ count: 3 });
        assert.dom('[data-test-release-version="0.9.1"]').includesText('Latest');
        assert.dom('[data-test-release-version="0.9.0"]').includesText('Installed');
        assert.dom('[data-test-release-version="0.8.4"]').includesText('Yanked');
        assert.dom('[data-test-release-action="0.9.0"]').isDisabled('the installed version');
        assert.dom('[data-test-release-action="0.8.4"]').isDisabled('a yanked version');
        assert.dom('[data-test-versions-table]').includesText('2.1 MB');

        await click('[data-test-release-action="0.9.1"]');
        assert.deepEqual(this.owner.lookup('service:extension-installer').calls, [['update', '0.9.1']]);
    });

    test('cloud-catalog records say release history is not available', async function (assert) {
        this.fetch.responses['registry-extensions/ext_2/releases'] = { supported: false, releases: [{ version: '1.0.0', channel: 'stable', is_latest: true }] };
        this.set('extension', { id: 'ext_2', public_id: 'ext_2' });

        await render(hbs`<ExtensionPanel::Versions @extension={{this.extension}} />`);

        assert.dom('[data-test-versions-unsupported]').exists();
        assert.dom('[data-test-release-version]').exists({ count: 1 });
    });

    test('a failed load offers Retry', async function (assert) {
        this.fetch.responses['registry-extensions/ext_3/releases'] = new Error('offline');
        this.set('extension', { id: 'ext_3', public_id: 'ext_3' });

        await render(hbs`<ExtensionPanel::Versions @extension={{this.extension}} />`);

        assert.dom('[data-test-versions-error]').exists();
    });
});
