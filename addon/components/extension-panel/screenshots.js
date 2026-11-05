import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * Screenshots tab: a viewer with previous and next controls (arrow keys
 * while it has focus) and the thumbnails below.
 */
export default class ExtensionPanelScreenshotsComponent extends Component {
    @tracked index = 0;

    get screenshots() {
        return Array.from(this.args.extension?.screenshots ?? []);
    }

    get thumbnails() {
        return this.screenshots.map((screenshot, index) => ({ screenshot, index, number: index + 1, isActive: index === this.index }));
    }

    get current() {
        return this.screenshots[this.index] ?? null;
    }

    get position() {
        return this.screenshots.length ? `${this.index + 1} / ${this.screenshots.length}` : null;
    }

    @action show(index) {
        const count = this.screenshots.length;
        if (count) {
            this.index = ((index % count) + count) % count;
        }
    }

    @action previous() {
        this.show(this.index - 1);
    }

    @action next() {
        this.show(this.index + 1);
    }

    @action onKeydown(event) {
        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            this.previous();
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            this.next();
        }
    }
}
