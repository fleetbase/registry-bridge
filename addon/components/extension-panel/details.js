import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';
import { priceOf } from '../../utils/extension-state';

const HOSTING = { both: 'both', cloud: 'cloud', self_hosted: 'self-hosted' };

/**
 * Details tab: version, publisher, packages, links, languages, hosting and
 * pricing, each row only when the listing has it.
 */
export default class ExtensionPanelDetailsComponent extends Component {
    @service intl;

    get extension() {
        return this.args.extension ?? {};
    }

    get rows() {
        const extension = this.extension;
        const t = (key, params) => this.intl.t(`registry-bridge.detail.fields.${key}`, params);
        const packages = extension.meta?.package_names ?? {};
        const price = priceOf(extension);
        const hosting = HOSTING[extension.meta?.hosting];
        const version = extension.latest_version || extension.current_bundle_version || extension.version;

        return [
            { label: t('version'), value: version ? `v${version}` : null },
            { label: t('installed-version'), value: extension.is_installed && extension.installed_version ? `v${extension.installed_version}` : null },
            { label: t('updated'), value: extension.updatedAt },
            { label: t('publisher'), value: extension.publisher_name },
            { label: t('packages'), value: [packages.npm, packages.composer].filter(Boolean).join(' · ') || null, mono: true },
            { label: t('website'), url: extension.website_url },
            { label: t('repository'), url: extension.repo_url },
            { label: t('support'), url: extension.support_url },
            { label: t('privacy'), url: extension.privacy_policy_url },
            { label: t('terms'), url: extension.tos_url },
            { label: t('languages'), value: (extension.languages ?? []).join(', ') || null },
            { label: t('copyright'), value: extension.copyright },
            { label: t('hosting'), value: hosting ? this.intl.t(`registry-bridge.detail.hosting.${hosting}`) : null },
            {
                label: t('pricing'),
                value: price.free
                    ? this.intl.t('registry-bridge.card.free')
                    : this.intl.t('registry-bridge.detail.pricing-one-time', {
                          price: formatCurrency(price.amount, price.currency),
                          currency: price.currency,
                      }),
            },
        ].filter((row) => row.value || row.url);
    }
}
