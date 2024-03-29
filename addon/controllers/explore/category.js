import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { task, timeout } from 'ember-concurrency';

export default class ExploreCategoryController extends Controller {
    @tracked category;
    @tracked query;

    @task({ restartable: true }) *search(event) {
        const query = event.target.value;
        if (query) {
            this.query = query;
            yield timeout(300);
        }
    }
}
