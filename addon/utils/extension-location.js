import { get } from '@ember/object';

const humanize = (value = '') =>
    String(value)
        .replace(/^engine:/, '')
        .split(/[-_\s]+/)
        .filter(Boolean)
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');

/** The console engine package an extension ships (`@scope/name-engine`), if any. */
export function enginePackageOf(extension) {
    return get(extension ?? {}, 'meta.package_names.npm') ?? get(extension ?? {}, 'meta.packages.npm') ?? null;
}

/**
 * Where an installed extension shows up in the console: the first menu item
 * any app registered with a component from the extension's engine (LocoNav
 * adds "LocoNav" to Fleet-Ops' settings), as the route to open it and a
 * breadcrumb label (`Fleet-Ops → Settings → LocoNav`).
 *
 * @param {Object} universe the ember-core universe service
 * @param {Object} extension a catalog record
 * @return {{ route: String, models: Array, path: Array<String> }|null}
 */
export default function locateExtension(universe, extension) {
    const engine = enginePackageOf(extension);
    const registries = universe?.registryService?.registries;
    if (!engine || !registries || typeof registries.entries !== 'function') {
        return null;
    }

    const appName = (sectionName) => {
        const slug = String(sectionName).replace(/^engine:/, '');
        const header = (universe.menuService?.getHeaderMenuItems?.() ?? []).find((item) => item?.slug === slug || item?.id === slug || item?.route === `console.${slug}`);
        return header?.title ?? humanize(slug);
    };

    for (const [sectionName, section] of registries.entries()) {
        const items = section?.['menu-item'];
        if (!Array.isArray(items) && typeof items?.toArray !== 'function') {
            continue;
        }
        for (const item of Array.isArray(items) ? items : items.toArray()) {
            const component = item?.component;
            const owner = component?.engine ?? component?.engineName ?? null;
            if (owner !== engine) {
                continue;
            }
            // Items without a route render in their app's virtual route (`console.fleet-ops.virtual` /:section/:slug)
            const route = item.route ?? (sectionName.startsWith('engine:') ? `console.${sectionName.replace(/^engine:/, '')}.virtual` : null);
            if (!route) {
                continue;
            }
            const slug = item.slug && item.slug !== '~' ? item.slug : null;
            const models = item.section && slug ? [item.section, slug] : slug ? [slug] : [];
            const path = [sectionName.startsWith('engine:') ? appName(sectionName) : null, item.section ? humanize(item.section) : null, item.title].filter(Boolean);
            return { route, models, queryParams: item.queryParams ?? {}, path };
        }
    }

    return null;
}
