import Model, { attr, belongsTo, hasMany } from '@ember-data/model';
import { computed } from '@ember/object';
import { format as formatDate, formatDistanceToNow, isValid as isValidDate } from 'date-fns';

/**
 * A Registry Service listing as the bridge maps it (`ServiceCatalog::transform`), decorated with this
 * instance's install and licence state.
 */
export default class RegistryExtensionModel extends Model {
    /** @ids */
    @attr('string') uuid;
    @attr('string') public_id;
    @attr('string') category_uuid;

    /** @relationships */
    @belongsTo('category') category;
    @hasMany('file') screenshots;

    /** @attributes */
    @attr('string', { defaultValue: 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/default-extension-icon.svg' }) icon_url;
    @attr('string') name;
    @attr('string') subtitle;
    @attr('string') description;
    @attr('string') promotional_text;
    @attr('string') fa_icon;
    @attr('string') slug;
    @attr('string') version;
    @attr('string', { defaultValue: 'published' }) status;
    @attr('string') category_name;
    @attr('string') publisher_name;
    @attr('boolean') payment_required;
    @attr('number') price;
    @attr('number') sale_price;
    @attr('boolean') on_sale;
    @attr('string', { defaultValue: 'USD' }) currency;
    @attr('number') installs_count;
    @attr('string') website_url;
    @attr('string') repo_url;
    @attr('string') support_url;
    @attr('string') privacy_policy_url;
    @attr('string') tos_url;
    @attr('string') copyright;
    @attr('string') primary_language;
    @attr('array') tags;
    @attr('array') languages;
    /** `hosting`, `compat`, `package_names`, `publisher`, `licence`, `install_blocked_code`, `install_blocked_reason`, `purchase_available`, … */
    @attr('object') meta;
    @attr('boolean') core_extension;
    @attr('boolean') is_purchased;
    @attr('boolean') is_installed;
    @attr('boolean') is_author;
    /** false when this instance cannot install the listing; `meta.install_blocked_reason` says why */
    @attr('boolean', { defaultValue: true }) is_installable;
    @attr('string') source;
    /** installed release, newest compatible release, and whether they differ */
    @attr('string') current_bundle_version;
    @attr('string') installed_version;
    @attr('string') latest_version;
    @attr('boolean', { defaultValue: false }) update_available;

    /** @dates */
    @attr('date') published_at;
    @attr('date') created_at;
    @attr('date') updated_at;

    /** @computed */
    @computed('updated_at') get updatedAgo() {
        if (!isValidDate(this.updated_at)) {
            return null;
        }

        return formatDistanceToNow(this.updated_at);
    }

    @computed('updated_at') get updatedAt() {
        if (!isValidDate(this.updated_at)) {
            return null;
        }

        return formatDate(this.updated_at, 'MMMM, dd yyyy');
    }
}
