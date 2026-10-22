import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task, timeout } from 'ember-concurrency';

export default class ExploreCategoryController extends Controller {
    @service intl;
    queryParams = ['query'];
    @tracked query;
    @tracked category;

    /**
     * Page title: "<Category> Extensions" when the category is known, otherwise the generic Explore title.
     */
    get title() {
        const categoryName = this.category?.name;
        if (categoryName) {
            return this.intl.t('registry-bridge.developers.extensions.explore.explore-category-extensions', { categoryName });
        }

        return this.intl.t('registry-bridge.developers.extensions.explore.explore-extensions');
    }

    @task({ restartable: true }) *search(event) {
        const query = event.target.value;
        if (query) {
            this.query = query;
            yield timeout(300);
        }
    }
}
