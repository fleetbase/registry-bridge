import { CTA, detailCta, priceOf } from './extension-state';

/**
 * The redesigned Extensions screens (Discover, categories, the extension page
 * and the library) all show an extension through the same few facts. This
 * module derives them from a catalog record, so the tile, the row and the
 * install button never disagree.
 */

/** Background colours for an extension without an icon: picked from its name, so it stays the same everywhere. */
export const MONOGRAM_COLORS = ['#0f766e', '#0369a1', '#4338ca', '#6d28d9', '#be123c', '#b45309', '#047857', '#c2410c', '#0e7490', '#a21caf'];

/** The install stages the button shows, in order; the server's job stages map onto these. */
export const STAGES = ['downloading', 'installing', 'finishing'];

/** The installer's plain phases (utils/installer-job INSTALLER_PHASES) as the button's three labels. */
export const PHASE_STAGES = { prepare: 'downloading', download: 'downloading', install: 'installing', remove: 'installing', finish: 'finishing' };

function hash(text = '') {
    let value = 0;
    for (let i = 0; i < text.length; i++) {
        value = (value * 31 + text.charCodeAt(i)) >>> 0;
    }
    return value;
}

/** The monogram (letter and colour) for an extension that has no icon image. */
export function monogram(extension = {}) {
    const name = String(extension.name || extension.slug || '?').trim();
    const letter = (name.match(/[\p{L}\p{N}]/u) || ['?'])[0].toUpperCase();
    return { letter, color: MONOGRAM_COLORS[hash(name.toLowerCase()) % MONOGRAM_COLORS.length] };
}

/** Whether the publisher is verified (Fleetbase, or a publisher the registry checked). */
export function isVerified(extension = {}) {
    return extension?.meta?.publisher?.is_verified === true;
}

/** The plain compatibility verdict code: `ready`, `needs_update`, `unavailable`, or null when the API sent none. */
export function verdictOf(extension = {}) {
    const verdict = extension?.meta?.compatibility?.verdict;
    return ['ready', 'needs_update', 'unavailable'].includes(verdict) ? verdict : null;
}

/**
 * Normalise an install job's progress into what the button draws: one of
 * STAGES and a 0-100 percent. Accepts `{ stage, percent }`, an installer event
 * or job record (`{ action, phase, percent }`, see utils/installer-job), or an
 * older record (`{ step, steps }`); anything else means no install is running.
 */
export function progressOf(progress) {
    if (!progress || typeof progress !== 'object') {
        return null;
    }

    let percent = Number(progress.percent ?? progress.progress);
    if (!Number.isFinite(percent)) {
        const step = Number(progress.step);
        const steps = Number(progress.steps);
        percent = Number.isFinite(step) && Number.isFinite(steps) && steps > 0 ? (step / steps) * 100 : 0;
    }
    percent = Math.max(0, Math.min(100, Math.round(percent)));

    let stage = STAGES.includes(progress.stage) ? progress.stage : (PHASE_STAGES[progress.phase] ?? null);
    if (!stage) {
        stage = percent < 30 ? 'downloading' : percent < 90 ? 'installing' : 'finishing';
    }

    const kind = progress.kind ?? progress.action;
    return { stage, percent, kind: kind === 'update' ? 'update' : kind === 'uninstall' ? 'uninstall' : 'install' };
}

/**
 * What the install button shows and does. `kind` is one of:
 * - `get`          free and installable: "Get"
 * - `buy`          paid and not bought: "Buy · $X"
 * - `progress`     an install or update is running: the button is the progress bar
 * - `open`         installed: "Open"
 * - `update`       installed with an update ready: "Update"
 * - `blocked`      needs a newer Fleetbase first: "Can't install yet"
 * - `unavailable`  not for this setup (another hosting, no release): "Not available"
 * - `registration` this Fleetbase isn't connected to the registry yet
 * - `unauthorized` the user's role can't install or buy
 *
 * @param {Object} extension a catalog record (ServiceCatalog::transform)
 * @param {Object} [options]
 * @param {Object} [options.progress] the running job, see progressOf()
 * @param {Boolean} [options.canInstall]
 * @param {Boolean} [options.canPurchase]
 */
export function buttonState(extension = {}, { progress = null, canInstall = true, canPurchase = true } = {}) {
    const running = progressOf(progress);
    if (running && running.kind !== 'uninstall') {
        return { ...running, job: running.kind, kind: 'progress', primary: true };
    }

    const cta = detailCta(extension, { canInstall, canPurchase });
    const price = priceOf(extension);
    const verdict = verdictOf(extension);

    switch (cta.kind) {
        case CTA.INSTALLED:
        case CTA.AUTHOR:
            return extension.is_installed === true ? { kind: 'open', primary: false } : { kind: 'get', primary: true };
        case CTA.UPDATE:
            return { kind: 'update', primary: true, version: cta.version };
        case CTA.PURCHASE:
            return { kind: 'buy', primary: true, price };
        case CTA.REGISTRATION:
            return { kind: 'registration', primary: false, disabled: true };
        case CTA.UNAUTHORIZED:
            return { kind: 'unauthorized', primary: false, disabled: true };
        case CTA.BLOCKED:
            return verdict === 'needs_update' ? { kind: 'blocked', primary: false, disabled: true } : { kind: 'unavailable', primary: false, disabled: true };
        default:
            if (verdict === 'needs_update') {
                return { kind: 'blocked', primary: false, disabled: true };
            }
            if (verdict === 'unavailable') {
                return { kind: 'unavailable', primary: false, disabled: true };
            }
            return price.free || extension.is_purchased === true ? { kind: 'get', primary: true, price } : { kind: 'buy', primary: true, price };
    }
}
