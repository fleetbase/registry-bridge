import { module, test } from 'qunit';
import { setupRenderingTest, StubFetchService, stubStoreQuery } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | extension-form', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', StubFetchService);
        stubStoreQuery(this.owner, []);
    });

    test('it renders the extension fields with the extension values', async function (assert) {
        this.set('extension', { name: 'Fleet-Ops', subtitle: 'Fleet management', tags: [], languages: [] });

        await render(hbs`<ExtensionForm @extension={{this.extension}} />`);

        const values = [...this.element.querySelectorAll('input')].map((input) => input.value);
        assert.ok(values.includes('Fleet-Ops'), 'the name input is bound to the extension');
        assert.dom(this.element).containsText('Extension Name');
        assert.dom(this.element).containsText('Upload Icon');
    });
});
