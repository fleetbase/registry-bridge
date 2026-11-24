import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import compatibilityVerdict from '../../utils/compatibility';

/**
 * Overview tab: description, promotional text, the compatibility verdict, install count,
 * last release, support and tags.
 */
export default class ExtensionPanelOverviewComponent extends Component {
    @service intl;

    get extension() {
        return this.args.extension ?? {};
    }

    /**
     * "Can I install this?" in plain words (Ready to install / Needs a Fleetbase update first / Not available
     * for your setup) with the version ranges behind a details toggle.
     */
    get compatibility() {
        return compatibilityVerdict(this.intl, this.extension);
    }

    get lastRelease() {
        const release = this.extension.meta?.latest_release;
        return release?.version ? release : null;
    }
}
