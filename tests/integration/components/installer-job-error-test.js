import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { setupIntl } from 'ember-intl/test-support';
import { render, click } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | installer-job-error', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    test('it shows the failed step, the message, the rollback outcome and the log', async function (assert) {
        this.set('error', {
            step: 'Installing the server package…',
            message: '`composer require acme/widgets-api:1.0.0` exited with code 2.',
            log: ['$ composer require acme/widgets-api:1.0.0 --no-interaction', 'Problem 1'],
            rolledBack: true,
            rebuildRequired: false,
        });

        await render(hbs`<InstallerJobError @error={{this.error}} />`);

        assert.dom('[data-test-installer-job-error]').hasAttribute('role', 'alert');
        assert.dom('[data-test-installer-job-error-step]').hasText('Failed while: Installing the server package…');
        assert.dom('[data-test-installer-job-error-message]').hasText('`composer require acme/widgets-api:1.0.0` exited with code 2.');
        assert.dom('[data-test-installer-job-error-rolled-back]').exists();
        assert.dom('[data-test-installer-job-error-log] .whitespace-pre-wrap').exists({ count: 2 });

        await click('[data-test-installer-job-error-log] summary');
        assert.dom('[data-test-installer-job-error-log]').hasAttribute('open');
    });

    test('it omits the log and rollback notes when there are none', async function (assert) {
        this.set('error', { step: null, message: 'The installer stopped before this job finished.', log: [], rolledBack: false });

        await render(hbs`<InstallerJobError @error={{this.error}} />`);

        assert.dom('[data-test-installer-job-error-step]').hasText('The job failed.');
        assert.dom('[data-test-installer-job-error-rolled-back]').doesNotExist();
        assert.dom('[data-test-installer-job-error-log]').doesNotExist();
    });
});
