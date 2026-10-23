/**
 * What an extension card and the detail panel show for a registry extension:
 * one of the nine card states (canvas C2) and the call to action in the
 * detail panel (canvas C4). Pure functions over the record's fields so they
 * can be tested without the app.
 *
 * Labels are returned as `{ key, params }` under `registry-bridge.*`; the
 * components translate them.
 */

export const REGISTRY_SERVICE = 'registry-service';

export const CARD_STATES = ['free', 'paid', 'on-sale', 'installed', 'update', 'purchased', 'self-managed', 'in-review', 'rejected'];

export const CTA = {
    INSTALL: 'install',
    PURCHASE: 'purchase',
    INSTALLED: 'installed',
    UPDATE: 'update',
    UNAUTHORIZED: 'unauthorized',
    AUTHOR: 'author',
    REGISTRATION: 'registration-required',
    BLOCKED: 'blocked',
    SELF_MANAGED: 'self-managed',
};

export const PERMISSIONS = {
    install: 'registry-bridge install extension',
    uninstall: 'registry-bridge uninstall extension',
    purchase: 'registry-bridge purchase extension',
};

const IN_REVIEW_STATUSES = ['pending', 'in_review', 'in-review', 'awaiting_review', 'awaiting-review'];

const label = (key, params = {}) => ({ key: `registry-bridge.${key}`, params });

function get(record, path) {
    return path.split('.').reduce((value, key) => (value === null || value === undefined ? undefined : value[key]), record);
}

export function isServiceRecord(extension = {}) {
    return extension.source === REGISTRY_SERVICE || get(extension, 'meta.source') === REGISTRY_SERVICE;
}

function toNumber(value) {
    const number = Number(value);
    return Number.isFinite(number) ? number : 0;
}

/**
 * The price shown on a card: free, the list price, or the sale price with the
 * list price struck through. Amounts are in the currency's minor unit, as
 * `format-currency` expects.
 */
export function priceOf(extension = {}) {
    const currency = extension.currency || 'USD';
    if (extension.payment_required !== true) {
        return { free: true, amount: 0, original: null, currency };
    }

    const price = toNumber(extension.price);
    const sale = toNumber(extension.sale_price);
    if (extension.on_sale === true && sale > 0 && sale < price) {
        return { free: false, amount: sale, original: price, currency };
    }

    return { free: false, amount: price, original: null, currency };
}

export function cardState(extension = {}) {
    const status = String(extension.status ?? '').toLowerCase();
    if (extension.is_author === true && status === 'rejected') {
        return 'rejected';
    }
    if (extension.is_author === true && IN_REVIEW_STATUSES.includes(status)) {
        return 'in-review';
    }
    if (extension.is_installed === true && extension.update_available === true) {
        return 'update';
    }
    if (extension.is_installed === true) {
        return 'installed';
    }
    if (extension.self_managed === true) {
        return 'self-managed';
    }

    const price = priceOf(extension);
    if (!price.free && extension.is_purchased === true) {
        return 'purchased';
    }
    if (!price.free && price.original !== null) {
        return 'on-sale';
    }

    return price.free ? 'free' : 'paid';
}

/**
 * Everything a card renders besides the record's own text: the state, the
 * price badge, the state badges and the footer action.
 *
 * `action.kind` is what the card does when the action is pressed:
 * `details` (open the panel), `install`, `update`, `self-managed` (open the
 * panel on the instructions), `portal` (the publisher portal) or `none`.
 */
export function cardModel(extension = {}) {
    const state = cardState(extension);
    const price = priceOf(extension);
    const latest = extension.latest_version || extension.current_bundle_version || extension.version;
    const badges = [];
    let priceBadge = price.free ? { label: label('card.free'), status: 'success' } : { amount: price.amount, original: price.original, currency: price.currency, status: 'info' };
    let action = { kind: 'details', label: label('card.view-details'), type: 'default' };

    switch (state) {
        case 'on-sale':
            badges.push({ label: label('card.on-sale'), status: 'warning' });
            break;
        case 'installed':
            badges.push({ label: label('card.installed'), status: 'success' });
            action = { kind: 'none', label: label('card.installed'), type: 'default', disabled: true };
            break;
        case 'update':
            badges.push({ label: label('card.update', { version: latest }), status: 'info' });
            if (extension.installed_version) {
                badges.push({ label: label('card.installed-version', { version: extension.installed_version }), status: 'success' });
            }
            action = { kind: 'update', label: label('card.update-to', { version: latest }), type: 'primary', permission: PERMISSIONS.install };
            break;
        case 'purchased':
            priceBadge = { label: label('card.purchased'), status: 'success' };
            badges.push({ label: label('card.ready-to-install'), status: 'info' });
            action =
                extension.is_installable === false
                    ? { kind: 'details', label: label('card.view-details'), type: 'default' }
                    : { kind: 'install', label: label('card.install'), type: 'primary', permission: PERMISSIONS.install };
            break;
        case 'self-managed':
            badges.push({ label: label('card.self-managed'), status: 'default' });
            action = { kind: 'self-managed', label: label('card.self-hosted'), type: 'magic' };
            break;
        case 'in-review':
            badges.push({ label: label('card.in-review'), status: 'warning' });
            action = { kind: 'portal', label: label('card.open-editor'), type: 'default' };
            break;
        case 'rejected':
            badges.push({ label: label('card.rejected'), status: 'error' });
            action = { kind: 'portal', label: label('card.see-feedback'), type: 'danger' };
            break;
    }

    if (get(extension, 'meta.publisher.is_verified') === true) {
        badges.push({ label: label('card.verified'), status: 'default' });
    }

    const hosting = get(extension, 'meta.hosting');
    if (state !== 'self-managed' && (hosting === 'self_hosted' || hosting === 'cloud')) {
        badges.push({ label: label(hosting === 'cloud' ? 'card.cloud-only' : 'card.self-hosted-only'), status: 'default' });
    }

    return { state, priceBadge, badges, action, version: latest || null };
}

/**
 * The call to action in the detail panel (canvas C4 CTA matrix).
 *
 * @param {Object} extension
 * @param {Object} context
 * @param {Boolean} context.canInstall the user may install, update and uninstall
 * @param {Boolean} context.canPurchase the user may purchase
 * @return {{ kind: String, permission?: String, reason?: String, code?: String, version?: String, installedVersion?: String, legacy?: Boolean }}
 */
export function detailCta(extension = {}, { canInstall = true, canPurchase = true } = {}) {
    const latest = extension.latest_version || extension.current_bundle_version || extension.version || null;
    const reason = get(extension, 'meta.install_blocked_reason') || null;
    const code = get(extension, 'meta.install_blocked_code') || null;
    const service = isServiceRecord(extension);

    if (extension.is_author === true) {
        return { kind: CTA.AUTHOR };
    }

    if (extension.is_installed === true) {
        if (extension.update_available !== true) {
            return { kind: CTA.INSTALLED, version: extension.installed_version || latest };
        }
        if (!canInstall) {
            return { kind: CTA.UNAUTHORIZED, permission: PERMISSIONS.install };
        }
        return { kind: CTA.UPDATE, version: latest, installedVersion: extension.installed_version || null };
    }

    if (extension.self_managed === true) {
        return { kind: CTA.SELF_MANAGED };
    }

    const needsLicence = priceOf(extension).free === false && extension.is_purchased !== true;
    if (needsLicence && (!service || get(extension, 'meta.purchase_available') === true)) {
        // Cloud-catalog (v1) records keep the embedded Stripe checkout until that path is retired
        return canPurchase ? { kind: CTA.PURCHASE, legacy: !service } : { kind: CTA.UNAUTHORIZED, permission: PERMISSIONS.purchase };
    }

    if (extension.is_installable === false || reason) {
        if (code === 'not_registered') {
            return { kind: CTA.REGISTRATION, reason, code };
        }
        return { kind: CTA.BLOCKED, reason: reason || null, code };
    }

    if (!canInstall) {
        return { kind: CTA.UNAUTHORIZED, permission: PERMISSIONS.install };
    }

    return { kind: CTA.INSTALL, version: latest };
}
