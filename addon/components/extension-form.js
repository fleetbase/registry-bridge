import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ExtensionFormComponent extends Component {
    @service store;
    @service fetch;
    @service notifications;
    @service intl;
    @service modalsManager;
    @service extensionPanel;
    @tracked subscriptionModelOptions = ['flat_rate', 'tiered', 'usage'];
    @tracked billingPeriodOptions = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'];
    @tracked uploadQueue = [];
    listingDetailsPanelActions = [
        {
            type: 'link',
            size: 'xs',
            text: 'Preview Listing',
            onClick: this.previewListing,
        },
    ];
    acceptedImageTypes = ['image/jpeg', 'image/png', 'image/gif'];
    acceptedBundleTypes = [
        'application/zip',
        'application/x-zip',
        'application/x-zip-compressed',
        'application/x-compressed',
        'multipart/x-zip',
        'application/x-tar',
        'application/gzip',
        'application/x-gzip',
        'application/x-tgz',
        'application/x-bzip2',
        'application/x-xz',
    ];

    @task *uploadIcon(file) {
        const { extension, onIconUploaded } = this.args;

        yield this.fetch.uploadFile.perform(
            file,
            {
                path: `uploads/extensions/${extension.id}/icons`,
                subject_uuid: extension.id,
                subject_type: 'registry-bridge:registry-extension',
                type: 'extension_icon',
            },
            (uploadedFile) => {
                extension.setProperties({
                    icon: uploadedFile,
                    icon_uuid: uploadedFile.id,
                    icon_url: uploadedFile.url,
                });

                if (typeof onIconUploaded === 'function') {
                    onIconUploaded(uploadedFile);
                }

                return extension.save();
            }
        );
    }

    @action queueFile(file) {
        // since we have dropzone and upload button within dropzone validate the file state first
        // as this method can be called twice from both functions
        if (['queued', 'failed', 'timed_out', 'aborted'].indexOf(file.state) === -1) {
            return;
        }

        const { extension, onScreenshotUploaded } = this.args;

        // Queue and upload immediatley
        this.uploadQueue.pushObject(file);
        this.fetch.uploadFile.perform(
            file,
            {
                path: `uploads/extensions/${extension.id}/screenshots`,
                subject_uuid: extension.id,
                subject_type: 'registry-bridge:registry-extension',
                type: 'extension_screenshot',
            },
            (uploadedFile) => {
                extension.screenshots.pushObject(uploadedFile);
                this.uploadQueue.removeObject(file);
                if (typeof onScreenshotUploaded === 'function') {
                    onScreenshotUploaded(uploadedFile);
                }
            },
            () => {
                this.uploadQueue.removeObject(file);
                // remove file from queue
                if (file.queue && typeof file.queue.remove === 'function') {
                    file.queue.remove(file);
                }
            }
        );
    }

    @action selectBundle() {
        const { extension, onBundleSelected } = this.args;

        this.modalsManager.show('modals/select-extension-bundle', {
            title: 'Select extension bundle',
            modalClass: 'modal-md',
            acceptButtonText: 'Done',
            hideDeclineButton: true,
            extension,
            onBundleSelected: (bundle) => {
                extension.setProperties({
                    next_bundle_uuid: bundle.id,
                    next_bundle_id: bundle.bundle_id,
                    next_bundle_filename: bundle.bundle_filename,
                    next_bundle: bundle,
                });

                if (typeof onBundleSelected === 'function') {
                    onBundleSelected(bundle);
                }

                this.modalsManager.done();
            },
        });
    }

    @action removeFile(file) {
        return file.destroyRecord();
    }

    /**
     * Preview the listing as operators see it: the extension detail panel.
     */
    @action previewListing() {
        return this.extensionPanel.open(this.args.extension);
    }
}
