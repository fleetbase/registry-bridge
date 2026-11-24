import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { isVerified, openExtensionPage, screenshotsOf } from '../../utils/store-model';

/**
 * The Discover banner: one featured extension at a time (up to three, with
 * dots to switch), its first screenshot beside it, and the install button.
 *
 * @argument {Array} items featured extensions
 * @argument {Function} [onOpen]
 */
export default class RegistryFeaturedComponent extends Component {
    @service hostRouter;
    @tracked index = 0;

    get items() {
        return (this.args.items ?? []).slice(0, 3);
    }

    get current() {
        return this.items[Math.min(this.index, this.items.length - 1)] ?? null;
    }

    get dots() {
        return this.items.length > 1 ? this.items.map((item, i) => ({ i, current: i === this.index, name: item.name })) : [];
    }

    get verified() {
        return isVerified(this.current);
    }

    get screenshot() {
        return screenshotsOf(this.current)[0]?.url ?? null;
    }

    get meta() {
        return [this.current?.category_name].filter(Boolean).join(' · ');
    }

    @action show(i) {
        this.index = i;
    }

    @action open() {
        if (typeof this.args.onOpen === 'function') {
            return this.args.onOpen(this.current);
        }
        return openExtensionPage(this.hostRouter, this.current);
    }
}
