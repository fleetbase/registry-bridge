import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { htmlSafe } from '@ember/template';
import { monogram } from '../../utils/store-model';

/** Pixel sizes for `@size`: row 32, tile 44, featured 64, extension page 104. */
const SIZES = { xs: 28, sm: 32, md: 44, lg: 64, xl: 104 };

/**
 * An extension's icon: its uploaded image, or a coloured monogram when it has
 * none (or the image fails to load), so every extension has a recognisable mark.
 *
 * @argument {Object} extension
 * @argument {String} [size] xs | sm | md | lg | xl (default md)
 */
export default class RegistryExtensionIconComponent extends Component {
    @tracked failed = false;

    get px() {
        return SIZES[this.args.size] ?? SIZES.md;
    }

    get src() {
        return this.failed ? null : this.args.extension?.icon_url || null;
    }

    get monogram() {
        return monogram(this.args.extension);
    }

    get style() {
        const px = this.px;
        const radius = Math.round(px * 0.24);
        const font = Math.round(px * 0.42);
        const background = this.src ? 'transparent' : this.monogram.color;
        return htmlSafe(`width:${px}px;height:${px}px;border-radius:${radius}px;font-size:${font}px;background:${background}`);
    }

    @action onError() {
        this.failed = true;
    }
}
