import buildRoutes from 'ember-engines/routes';

export default buildRoutes(function () {
    this.route('installed');
    this.route('purchased');
    this.route('developers', function () {
        this.route('extensions', function () {
            this.route('index', { path: '/' });
            this.route('new');
            this.route('edit', { path: '/distribution/:public_id' }, function () {
                this.route('index', { path: '/' });
                this.route('details');
                this.route('bundles');
                this.route('monetize');
            });
        });
        this.route('analytics');
        this.route('payments', function () {
            this.route('index', { path: '/' });
            this.route('onboard');
            this.route('settings');
        });
        this.route('credentials');
    });
    this.route('explore', { path: '/' }, function () {
        this.route('index', { path: '/' });
        this.route('category', { path: '/:slug' });
    });
});
