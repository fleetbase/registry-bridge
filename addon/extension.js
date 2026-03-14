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
                {
                    title: 'My Extensions',
                    description: 'Manage and publish your own extensions to the registry.',
                    icon: 'code-branch',
                    route: 'console.extensions.developers.extensions',
                },
                {
                    title: 'Developer Analytics',
                    description: 'Track installs, revenue, and usage for your published extensions.',
                    icon: 'chart-line',
                    route: 'console.extensions.developers.analytics',
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
