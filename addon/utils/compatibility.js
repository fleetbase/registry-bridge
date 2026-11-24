import { get } from '@ember/object';

/**
 * How each verdict looks: the InfoBlock type and icon.
 */
export const VERDICTS = {
    ready: { type: 'success', icon: 'circle-check' },
    needs_update: { type: 'warning', icon: 'circle-exclamation' },
    unavailable: { type: 'danger', icon: 'circle-xmark' },
};

/**
 * Reason codes the server sends (ServiceCatalog::compatibility) that have
 * their own sentence; anything else falls back to the verdict's default.
 */
export const REASONS = ['ok', 'core_too_old', 'console_too_old', 'core_too_new', 'console_too_new', 'hosting', 'no_release', 'not_registered', 'needs_console_build'];

/**
 * "Can I install this?" for an extension, in plain words first: a verdict
 * label (Ready to install / Needs a Fleetbase update first / Not available
 * for your setup) and one sentence why. The version ranges and this
 * instance's versions are kept as `technical` lines for a details view.
 *
 * Reads `meta.compatibility`; listings from an API without it fall back to
 * the raw `meta.compat` ranges as technical lines and no verdict.
 *
 * @param {Object} intl
 * @param {Object} extension
 * @return {{ verdict: String|null, label: String|null, reason: String|null, type: String, icon: String, technical: Array<String> }|null}
 */
export default function compatibilityVerdict(intl, extension) {
    const t = (key, options) => intl.t(`registry-bridge.compatibility.${key}`, options);
    const compatibility = get(extension ?? {}, 'meta.compatibility');
    const raw = get(extension ?? {}, 'meta.compat') ?? {};
    const requires = compatibility?.requires ?? {
        console: raw.console ?? raw.fleetbase ?? raw['@fleetbase/console'] ?? null,
        api: raw.api ?? raw['core-api'] ?? raw['fleetbase/core-api'] ?? null,
    };
    const current = compatibility?.current ?? {};

    const technical = [];
    if (requires.console || requires.api) {
        technical.push(t('requires', { console: requires.console ?? '*', api: requires.api ?? '*' }));
    }
    if (current.console || current.api) {
        technical.push(t('current', { console: current.console ?? '?', api: current.api ?? '?' }));
    }

    const verdict = compatibility?.verdict;
    if (!VERDICTS[verdict]) {
        return technical.length ? { verdict: null, label: null, reason: null, type: 'info', icon: 'code-compare', technical } : null;
    }

    let reason = REASONS.includes(compatibility.reason) ? compatibility.reason : `default-${verdict}`;
    if (reason === 'hosting') {
        // Which kind of instance the extension is for: Fleetbase Cloud or self-hosted.
        reason = get(extension, 'meta.hosting') === 'cloud' ? 'hosting-cloud' : 'hosting-self-hosted';
    }

    return {
        verdict,
        label: t(`verdicts.${verdict}`),
        reason: t(`reasons.${reason}`),
        ...VERDICTS[verdict],
        technical,
    };
}
