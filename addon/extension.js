import { MenuItem, ExtensionComponent } from '@fleetbase/ember-core/contracts';

export default {
    setupExtension(app, universe) {
        const menuService = universe.getService('menu');

        // Register menu item in header
        menuService.registerHeaderMenuItem('Extensions', 'console.extensions', {
            icon: 'shapes',
            priority: 99,
            id: 'registry-bridge',
            slug: 'registry-bridge',
            description: 'Discover, install, and publish extensions to the Fleetbase extension registry.',
            shortcuts: [
                {
                    title: 'Explore',
                    description: 'Browse and discover extensions available in the registry.',
                    icon: 'compass',
                    route: 'console.extensions.explore',
                },
                {
                    title: 'Installed',
                    description: 'View and manage extensions currently installed in your console.',
                    icon: 'puzzle-piece',
                    route: 'console.extensions.installed',
                },
                {
                    title: 'Purchased',
                    description: 'Access extensions you have purchased from the registry.',
                    icon: 'receipt',
                    route: 'console.extensions.purchased',
                },
            ],
        });

        // Register admin controls
        menuService.registerAdminMenuPanel(
            'Extensions Registry',
            [
                new MenuItem({
                    title: 'Registry Config',
                    icon: 'gear',
                    component: new ExtensionComponent('@fleetbase/registry-bridge-engine', 'registry-admin-config'),
                }),
                new MenuItem({
                    title: 'Awaiting Review',
                    icon: 'gavel',
                    component: new ExtensionComponent('@fleetbase/registry-bridge-engine', 'extension-reviewer-control'),
                }),
                new MenuItem({
                    title: 'Pending Publish',
                    icon: 'rocket',
                    component: new ExtensionComponent('@fleetbase/registry-bridge-engine', 'extension-pending-publish-viewer'),
                }),
            ],
            {
                slug: 'extension-registry',
            }
        );
    },
};
