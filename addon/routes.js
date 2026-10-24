import buildRoutes from 'ember-engines/routes';

export default buildRoutes(function () {
    this.route('installed');
    this.route('purchased');
    this.route('explore', { path: '/' }, function () {
        this.route('index', { path: '/' });
        this.route('category', { path: '/:slug' });
    });
});
