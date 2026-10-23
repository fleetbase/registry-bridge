import { module, test } from 'qunit';
import { setupRenderingTest, ModalsManagerStub } from 'dummy/tests/helpers';
import { fillIn, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

const extension = { id: 'ext_1', slug: 'pallet', name: 'Pallet WMS', meta: { package_names: { npm: '@acme/pallet-engine', composer: 'acme/pallet-api' } } };
const steps = (...statuses) => ['server', 'engine', 'build'].map((key, index) => ({ key, status: statuses[index] }));

module('Integration | Component | modals/extension-installer', function (hooks) {
    setupRenderingTest(hooks);

    test('confirm names what will be installed and the three steps', async function (assert) {
        this.set('options', { jobAction: 'install', phase: 'confirm', extension, companyName: 'Acme Fleet', steps: steps('pending', 'pending', 'pending') });
        await render(hbs`<Modals::ExtensionInstaller @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom('[data-test-installer-intro]').includesText('for Acme Fleet');
        assert.dom('[data-test-installer-step]').exists({ count: 3 });
        assert.dom('[data-test-installer-step="server"]').includesText('Install server package');
        assert.dom('[data-test-installer-step="server"]').includesText('acme/pallet-api');
        assert.dom('[data-test-installer-step="engine"]').includesText('@acme/pallet-engine');
    });

    test('progress marks the steps and shows the live step', async function (assert) {
        this.set('options', {
            jobAction: 'install',
            phase: 'progress',
            extension,
            steps: steps('done', 'active', 'pending'),
            stepDescription: 'Installing the console engine…',
            progress: 40,
        });
        await render(hbs`<Modals::ExtensionInstaller @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom('[data-test-installer-progress-title]').hasText('Installing Pallet WMS…');
        assert.dom('[data-test-installer-progress-title]').hasAttribute('aria-live', 'polite');
        assert.dom('[data-test-installer-step="server"]').hasAttribute('data-status', 'done');
        assert.dom('[data-test-installer-step="engine"] [data-test-installer-step-description]').hasText('Installing the console engine…');
    });

    test('success counts down to the reload', async function (assert) {
        this.set('options', { jobAction: 'install', phase: 'success', extension, version: '0.9.1', steps: steps('done', 'done', 'done'), secondsLeft: 6 });
        await render(hbs`<Modals::ExtensionInstaller @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom('[data-test-installer-success]').includesText('Pallet WMS v0.9.1 is installed');
        assert.dom('[data-test-installer-countdown]').hasText('Reloading the console in 6 s so the new section appears.');
    });

    test('failure shows the stage, message, rollback and the CLI fallback', async function (assert) {
        this.set('options', {
            jobAction: 'install',
            phase: 'failure',
            extension,
            steps: steps('done', 'failed', 'pending'),
            installerError: { step: 'Installing the console engine…', message: 'npm install exited with code 1', log: ['ERR peer dep'], rolledBack: true },
        });
        await render(hbs`<Modals::ExtensionInstaller @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom('[data-test-installer-failure]').hasAttribute('role', 'alert');
        assert.dom('[data-test-installer-step="engine"]').hasAttribute('data-status', 'failed');
        assert.dom('[data-test-installer-job-error-step]').includesText('Installing the console engine…');
        assert.dom('[data-test-installer-job-error-message]').hasText('npm install exited with code 1');
        assert.dom('[data-test-installer-job-error-rolled-back]').exists();
        assert.dom('[data-test-installer-job-error-log]').includesText('ERR peer dep');
        assert.dom('[data-test-extension-installer]').includesText('flb install pallet');
    });

    test('uninstall warns and enables Uninstall once the name is typed', async function (assert) {
        this.owner.register('service:modals-manager', ModalsManagerStub);
        const modals = this.owner.lookup('service:modals-manager');
        modals.current = {
            component: 'modals/extension-installer',
            options: { jobAction: 'uninstall', phase: 'confirm', extension, steps: steps('pending', 'pending', 'pending'), acceptButtonDisabled: false },
        };
        this.set('options', modals.current.options);
        await render(hbs`<Modals::ExtensionInstaller @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom('[data-test-extension-installer]').includesText('permanently deletes its settings and data');
        assert.dom('[data-test-installer-step="server"]').includesText('Remove server package');

        await fillIn('[data-test-installer-confirm-name]', 'Pallet');
        assert.true(modals.options.acceptButtonDisabled);
        await fillIn('[data-test-installer-confirm-name]', 'Pallet WMS');
        assert.false(modals.options.acceptButtonDisabled);
    });
});
