import Component from '@glimmer/component';
import { action } from '@ember/object';
import { guidFor } from '@ember/object/internals';

/**
 * Sort and "Works with my Fleetbase" above a results list. `@catalog` is the
 * Explore (or category) controller.
 *
 * @argument {Object} catalog
 * @argument {Boolean} [showCompatible] default true
 */
export default class RegistryResultsToolbarComponent extends Component {
    sortId = `${guidFor(this)}-sort`;

    get sort() {
        return this.args.catalog?.sort || 'popular';
    }

    get showCompatible() {
        return this.args.showCompatible !== false;
    }

    @action changeSort(event) {
        this.args.catalog?.setSort(event.target.value);
    }
}
