import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { later } from '@ember/runloop';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';
import isModel from '@fleetbase/ember-core/utils/is-model';

function removeParamFromCurrentUrl(paramToRemove) {
    const url = new URL(window.location.href);
    url.searchParams.delete(paramToRemove);
    window.history.pushState({ path: url.href }, '', url.href);
}

function addParamToCurrentUrl(paramName, paramValue) {
    const url = new URL(window.location.href);
    url.searchParams.set(paramName, paramValue);
    window.history.pushState({ path: url.href }, '', url.href);
}

export default class ExtensionCardComponent extends Component {
    @service modalsManager;
    @service notifications;
    @service currentUser;
    @service socket;
    @service fetch;
    @service stripe;
    @service urlSearchParams;
    @service abilities;
    @tracked extension;

    constructor(owner, { resource }) {
        super(...arguments);
        this.extension = resource;
        this.checkForCheckoutSession();
    }

    @action onExtenstionUpdated(el, [extension]) {
        this.extension = extension;
    }

    @action onClick(options = {}) {
        const installChannel = `install.${this.currentUser.companyId}.${this.extension.id}`;
        const isAuthor = this.extension.is_author === true;
        const isSelfManaged = this.extension.self_managed === true;
        const isAlreadyPurchased = this.extension.is_purchased === true;
        const isAlreadyInstalled = this.extension.is_installed === true;
        const isPaymentRequired = !isAuthor && this.extension.payment_required === true && isAlreadyPurchased === false;
        const userCannotPurchase = isPaymentRequired && this.abilities.cannot('registry-bridge purchase extension');
        let acceptButtonText = isPaymentRequired ? `Purchase for ${formatCurrency(this.extension.price, this.extension.currency)}` : isAlreadyInstalled ? 'Installed' : 'Install';
        if (this.abilities.cannot('registry-bridge install extension')) {
            acceptButtonText = 'Unauthorized to Install';
        }
        const goBack = async (modal) => {
            await modal.done();
            later(
                this,
                () => {
                    this.onClick();
                },
                100
            );
        };

        if (typeof this.args.onClick === 'function') {
            this.args.onClick(this.extension);
        }

        addParamToCurrentUrl('extension_id', this.extension.id);
        this.modalsManager.show('modals/extension-details', {
            titleComponent: 'extension-modal-title',
            modalClass: 'flb--extension-modal modal-lg',
            modalHeaderClass: 'flb--extension-modal-header',
            acceptButtonText,
            acceptButtonIcon: isPaymentRequired ? 'credit-card' : isAlreadyInstalled ? 'check' : 'download',
            acceptButtonDisabled: this.abilities.cannot('registry-bridge install extension') || isAlreadyInstalled || userCannotPurchase,
            acceptButtonScheme: isPaymentRequired ? 'success' : 'primary',
            declineButtonText: 'Done',
            process: null,
            step: null,
            stepDescription: 'Awaiting install to begin...',
            progress: 0,
            extension: this.extension,
            viewSelfManagesInstallInstructions: () => {
                this.selfManagedInstallInstructions({
                    extension: this.extension,
                    confirm: goBack,
                    decline: goBack,
                });
            },
            confirm: async (modal) => {
                modal.startLoading();

                // Handle purchase flow
                if (isPaymentRequired) {
                    return this.startCheckoutSession();
                }

                // If self managed just prompt instructions
                if (isSelfManaged) {
                    await modal.done();
                    return later(
                        this,
                        () => {
                            return this.selfManagedInstallInstructions({
                                extension: this.extension,
                                confirm: goBack,
                                decline: goBack,
                            });
                        },
                        100
                    );
                }

                // Listen for install progress
                this.socket.listen(installChannel, ({ process, step, progress }) => {
                    let stepDescription;
                    switch (step) {
                        case 'server.install':
                            stepDescription = '(1/3) Installing extension...';
                            break;

                        case 'engine.install':
                            stepDescription = '(2/3) Installing extension...';
                            break;

                        case 'console.build':
                            stepDescription = '(3/3) Completing install...';
                            break;

                        default:
                            break;
                    }
                    modal.setOptions({ process, step, progress, stepDescription });
                });

                // Start install progress
                modal.setOption('progress', 5);

                // Run install
                try {
                    await this.extension.install();
                    this.notifications.info(`${this.extension.name} is now Installed.`);
                    later(
                        this,
                        () => {
                            window.location.reload(true);
                        },
                        600
                    );
                    removeParamFromCurrentUrl('extension_id');
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
            decline: (modal) => {
                modal.done();
                removeParamFromCurrentUrl('extension_id');
            },
            ...options,
        });
    }

    async selfManagedInstallInstructions(options = {}) {
        await this.modalsManager.done();
        this.modalsManager.show('modals/self-managed-install-instructions', {
            title: 'Install Extension to Self-Hosted Instance',
            hideDeclineButton: true,
            acceptButtonText: 'Done',
            ...options,
        });
    }

    async startCheckoutSession() {
        const checkout = await this.stripe.initEmbeddedCheckout({
            fetchClientSecret: this.fetchClientSecret.bind(this),
        });

        await this.modalsManager.done();
        later(
            this,
            () => {
                this.modalsManager.show('modals/extension-purchase-form', {
                    title: `Purchase the '${this.extension.name}' Extension`,
                    modalClass: 'stripe-extension-purchase',
                    modalFooterClass: 'hidden-i',
                    extension: this.extension,
                    checkoutElementInserted: (el) => {
                        checkout.mount(el);
                    },
                    decline: async (modal) => {
                        checkout.destroy();
                        await modal.done();
                        later(
                            this,
                            () => {
                                this.onClick();
                            },
                            100
                        );
                    },
                });
            },
            100
        );
    }

    async fetchClientSecret() {
        try {
            const { clientSecret } = await this.fetch.post(
                'payments/create-checkout-session',
                { extension: this.extension.id, uri: window.location.pathname },
                { namespace: '~registry/v1' }
            );

            return clientSecret;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    async checkForCheckoutSession() {
        later(
            this,
            async () => {
                const checkoutSessionId = this.urlSearchParams.get('checkout_session_id');
                const extensionId = this.urlSearchParams.get('extension_id');

                if (!checkoutSessionId && this.extension.id === extensionId) {
                    return this.onClick();
                }

                if (checkoutSessionId && this.extension.id === extensionId) {
                    this.modalsManager.show('modals/confirm-extension-purchase', {
                        title: 'Finalizing Purchase',
                        modalClass: 'finalize-extension-purchase',
                        loadingMessage: 'Completing purchase do not refresh or exit window...',
                        modalFooterClass: 'hidden-i',
                        backdropClose: false,
                    });

                    try {
                        const { status, extension } = await this.fetch.post(
                            'payments/get-checkout-session',
                            { checkout_session_id: checkoutSessionId, extension: this.extension.id },
                            { namespace: '~registry/v1' }
                        );

                        // Update this extension
                        const extensionModel = this.fetch.jsonToModel(extension, 'registry-extension');
                        if (isModel(extensionModel)) {
                            this.extension = extensionModel;
                        }

                        // Fire a callback
                        if (typeof this.args.onCheckoutCompleted === 'function') {
                            this.args.onCheckoutCompleted(this.extension, status);
                        }

                        if (status === 'complete' || status === 'purchase_complete') {
                            // remove checkout session id
                            removeParamFromCurrentUrl('checkout_session_id');

                            // close confirmation dialog and notify payment completed
                            await this.modalsManager.done();
                            if (status === 'complete') {
                                this.notifications.success('Payment Completed.');
                            }
                            later(
                                this,
                                () => {
                                    this.onClick();
                                },
                                100
                            );
                        }
                    } catch (error) {
                        this.notifications.serverError(error);
                    }
                }
            },
            300
        );
    }
}
