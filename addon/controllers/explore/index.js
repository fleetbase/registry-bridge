import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { task, timeout } from 'ember-concurrency';

export default class ExploreIndexController extends Controller {
    queryParams = ['query'];
    @tracked query;

    @task({ restartable: true }) *search(event) {
        const query = event.target.value;
        if (query) {
            this.query = query;
            yield timeout(300);
        }
    }
}
