import { module, test } from 'qunit';
import { setupRenderingTest, ModalsManagerStub } from 'dummy/tests/helpers';
import { fillIn, render, settled } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

const extension = {
    id: 'ext_1',
    slug: 'pallet',
    name: 'Pallet WMS',
    meta: {
        package_names: { npm: '@acme/pallet-engine', composer: 'acme/pallet-api' },
        compatibility: { verdict: 'ready', reason: 'ok', requires: { console: '>=0.7.0', api: '>=1.6.0' }, current: { console: '0.8.1', api: '2.0.4' } },
    },
};
const PHASES = { install: ['prepare', 'download', 'install', 'finish'], uninstall: ['prepare', 'remove', 'finish'] };
const steps = (...statuses) => PHASES[statuses.length === 3 ? 'uninstall' : 'install'].map((key, index) => ({ key, status: statuses[index] }));

module('Integration | Component | modals/extension-installer', function (hooks) {
    setupRenderingTest(hooks);

    test('confirm says in plain words whether it can be installed, and the four steps', async function (assert) {
        this.set('options', { jobAction: 'install', phase: 'confirm', extension, companyName: 'Acme Fleet', steps: steps('pending', 'pending', 'pending', 'pending') });
        await render(hbs`<Modals::ExtensionInstaller @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom('[data-test-installer-intro]').includesText('for Acme Fleet');
        assert.dom('[data-test-installer-compatibility="ready"]').includesText('Ready to install');
        assert.dom('[data-test-installer-step]').exists({ count: 4 });
        assert.dom('[data-test-installer-step="prepare"]').includesText('Getting things ready');
        assert.dom('[data-test-installer-step="finish"]').includesText('Finishing up');
        assert.dom('[data-test-extension-installer]').doesNotIncludeText('acme/pallet-api', 'no package names');
        assert.dom('[data-test-installer-time]').includesText('a minute or two');
    });

    test('confirm says when Fleetbase needs an update first', async function (assert) {
        const tooNew = {
            ...extension,
            meta: { ...extension.meta, compatibility: { verdict: 'needs_update', reason: 'core_too_old', requires: { api: '^2.1' }, current: { api: '2.0.4' } } },
        };
        this.set('options', { jobAction: 'install', phase: 'confirm', extension: tooNew, steps: steps('pending', 'pending', 'pending', 'pending') });
        await render(hbs`<Modals::ExtensionInstaller @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom('[data-test-installer-compatibility="needs_update"]').includesText('Needs a Fleetbase update first');
        assert.dom('[data-test-installer-compatibility="needs_update"]').includesText('Ask your administrator to update Fleetbase');
    });

    test('confirm says a console rebuild takes much longer, for installs and uninstalls', async function (assert) {
        const rebuilding = { ...extension, meta: { ...extension.meta, install_mode: 'build', uninstall_mode: 'build' } };
        this.set('options', { jobAction: 'update', phase: 'confirm', extension: rebuilding, steps: steps('pending', 'pending', 'pending', 'pending') });
        await render(hbs`<Modals::ExtensionInstaller @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom('[data-test-installer-time]').includesText('needs the console to be rebuilt, which can take 15 to 40 minutes');
        assert.dom('[data-test-installer-time]').doesNotIncludeText('a minute or two');
        assert.dom('[data-test-installer-step="install"]').includesText('Updating');

        this.set('options', { jobAction: 'uninstall', phase: 'confirm', extension: rebuilding, steps: steps('pending', 'pending', 'pending') });
        await settled();
        assert.dom('[data-test-installer-time]').includesText('This also rebuilds the console');

        this.set('options', { jobAction: 'uninstall', phase: 'confirm', extension, steps: steps('pending', 'pending', 'pending') });
        await settled();
        assert.dom('[data-test-installer-time]').hasText("Takes about a minute. This tab reloads when it's done.");
    });

    test('progress marks the steps, shows one bar and keeps the technical stages behind details', async function (assert) {
        this.set('options', {
            jobAction: 'install',
            phase: 'progress',
            extension,
            steps: steps('done', 'done', 'active', 'pending'),
            stepDescription: 'Installing Pallet WMS…',
            progress: 40,
            details: [
                { key: 'resolve', label: 'Resolving the release…', status: 'done', seconds: 1.2 },
                { key: 'composer', label: 'Installing the server package…', status: 'active', seconds: null },
            ],
        });
        await render(hbs`<Modals::ExtensionInstaller @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom('[data-test-installer-progress-title]').hasText('Installing Pallet WMS…');
        assert.dom('[data-test-installer-progress-title]').hasAttribute('aria-live', 'polite');
        assert.dom('[data-test-installer-step="download"]').hasAttribute('data-status', 'done');
        assert.dom('[data-test-installer-step="install"]').hasAttribute('data-status', 'active');
        assert.dom('[data-test-installer-progress-bar]').exists({ count: 1 });
        assert.dom('[data-test-installer-details]').doesNotHaveAttribute('open', 'details start collapsed');
        assert.dom('[data-test-installer-details-stage="resolve"]').includesText('1.2 s');

        this.set('options', { ...this.options, canSeeDetails: false });
        await settled();
        assert.dom('[data-test-installer-details]').doesNotExist('no details for people who may not install');
    });

    test('success counts down to the reload', async function (assert) {
        this.set('options', { jobAction: 'install', phase: 'success', extension, version: '0.9.1', steps: steps('done', 'done', 'done', 'done'), secondsLeft: 6 });
        await render(hbs`<Modals::ExtensionInstaller @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom('[data-test-installer-success]').includesText('Pallet WMS v0.9.1 is installed');
        assert.dom('[data-test-installer-countdown]').hasText('Reloading the console in 6 s so the new section appears.');
    });

    test('failure says what happened in plain words, with the stage, message, rollback and the CLI fallback in details', async function (assert) {
        this.set('options', {
            jobAction: 'install',
            phase: 'failure',
            extension,
            steps: steps('done', 'done', 'failed', 'pending'),
            installerError: {
                summary: "The installation didn't finish. Nothing was changed, so you can safely try again.",
                step: 'Installing the console engine…',
                message: 'npm install exited with code 1',
                log: ['ERR peer dep'],
                rolledBack: true,
            },
        });
        await render(hbs`<Modals::ExtensionInstaller @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom('[data-test-installer-failure]').hasAttribute('role', 'alert');
        assert.dom('[data-test-installer-job-error-summary]').includesText('Nothing was changed');
        assert.dom('[data-test-installer-step="install"]').hasAttribute('data-status', 'failed');
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
        assert.dom('[data-test-installer-step="remove"]').includesText('Removing');
        assert.dom('[data-test-installer-compatibility]').doesNotExist('no install verdict on an uninstall');

        await fillIn('[data-test-installer-confirm-name]', 'Pallet');
        assert.true(modals.options.acceptButtonDisabled);
        await fillIn('[data-test-installer-confirm-name]', 'Pallet WMS');
        assert.false(modals.options.acceptButtonDisabled);
    });
});
