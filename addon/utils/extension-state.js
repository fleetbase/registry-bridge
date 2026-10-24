/**
 * What an extension card and the detail panel show for a Registry Service
 * listing: one of the card states (canvas C2) and the call to action in the
 * detail panel (canvas C4). Pure functions over the record's fields so they
 * can be tested without the app.
 *
 * Labels are returned as `{ key, params }` under `registry-bridge.*`; the
 * components translate them.
 */

export const CARD_STATES = ['free', 'paid', 'on-sale', 'installed', 'update', 'purchased', 'unavailable'];

/** Install blocks that mean "not on this instance" rather than "not yet": the card says so. */
export const UNAVAILABLE_CODES = ['hosting', 'no_release'];

export const CTA = {
    INSTALL: 'install',
    PURCHASE: 'purchase',
    INSTALLED: 'installed',
    UPDATE: 'update',
    UNAUTHORIZED: 'unauthorized',
    AUTHOR: 'author',
    REGISTRATION: 'registration-required',
    BLOCKED: 'blocked',
};

export const PERMISSIONS = {
    install: 'registry-bridge install extension',
    uninstall: 'registry-bridge uninstall extension',
    purchase: 'registry-bridge purchase extension',
};

const label = (key, params = {}) => ({ key: `registry-bridge.${key}`, params });

function get(record, path) {
    return path.split('.').reduce((value, key) => (value === null || value === undefined ? undefined : value[key]), record);
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

/** Why this instance cannot install the listing, when it is about the instance rather than a licence. */
export function unavailableReason(extension = {}) {
    const code = get(extension, 'meta.install_blocked_code');
    return extension.is_installed !== true && UNAVAILABLE_CODES.includes(code) ? get(extension, 'meta.install_blocked_reason') || null : null;
}

export function cardState(extension = {}) {
    if (extension.is_installed === true && extension.update_available === true) {
        return 'update';
    }
    if (extension.is_installed === true) {
        return 'installed';
    }
    if (UNAVAILABLE_CODES.includes(get(extension, 'meta.install_blocked_code'))) {
        return 'unavailable';
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
 * price badge, the state badges (an unavailable listing's badge carries the
 * reason as `help`) and the footer action.
 *
 * `action.kind` is what the card does when the action is pressed:
 * `details` (open the panel), `install`, `update` or `none`.
 */
export function cardModel(extension = {}) {
    const state = cardState(extension);
    const price = priceOf(extension);
    const latest = extension.latest_version || extension.current_bundle_version || extension.version;
    const hosting = get(extension, 'meta.hosting');
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
        case 'unavailable':
            badges.push({ label: label('card.unavailable'), status: 'warning', help: unavailableReason(extension) });
            break;
    }

    if (get(extension, 'meta.publisher.is_verified') === true) {
        badges.push({ label: label('card.verified'), status: 'default' });
    }

    if (hosting === 'self_hosted' || hosting === 'cloud') {
        badges.push({ label: label(hosting === 'cloud' ? 'card.cloud-only' : 'card.self-hosted-only'), status: 'default' });
    }

    return { state, priceBadge, badges, action, version: latest || null, unavailableReason: unavailableReason(extension) };
}

/**
 * The call to action in the detail panel (canvas C4 CTA matrix).
 *
 * @param {Object} extension
 * @param {Object} context
 * @param {Boolean} context.canInstall the user may install, update and uninstall
 * @param {Boolean} context.canPurchase the user may purchase
 * @return {{ kind: String, permission?: String, reason?: String, code?: String, version?: String, installedVersion?: String }}
 */
export function detailCta(extension = {}, { canInstall = true, canPurchase = true } = {}) {
    const latest = extension.latest_version || extension.current_bundle_version || extension.version || null;
    const reason = get(extension, 'meta.install_blocked_reason') || null;
    const code = get(extension, 'meta.install_blocked_code') || null;

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

    if (code === 'licence_required' && get(extension, 'meta.purchase_available') === true) {
        return canPurchase ? { kind: CTA.PURCHASE } : { kind: CTA.UNAUTHORIZED, permission: PERMISSIONS.purchase };
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
