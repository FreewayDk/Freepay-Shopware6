import template from './freepay-capture-modal.html.twig';

const { Component, Mixin } = Shopware;

Component.register('freepay-capture-modal', {
    template,

    mixins: [
        Mixin.getByName('notification'),
    ],

    props: {
        orderId: {
            type: String,
            required: true,
        },
        remaining: {
            type: Number,
            required: false,
            default: 0,
        },
    },

    data() {
        return {
            amount: this.remaining > 0 ? this.remaining : null,
            isLoading: false,
        };
    },

    methods: {
        onCloseModal() {
            this.$emit('modal-close');
        },

        onConfirmCapture() {
            const amount = parseFloat(this.amount);

            if (!amount || amount <= 0) {
                this.createNotificationError({
                    message: this.$tc('freepay.capture.invalidAmount'),
                });
                return;
            }

            this.isLoading = true;

            try {
                // syncService is an ApiService: it exposes the configured httpClient
                // and getBasicHeaders(). loginService does NOT have getBasicHeaders()
                // in Shopware 6.7.
                const syncService = Shopware.Service('syncService');

                syncService.httpClient
                    .post(
                        `/_action/freepay/capture/${this.orderId}`,
                        { amount },
                        { headers: syncService.getBasicHeaders() }
                    )
                    .then(() => {
                        this.createNotificationSuccess({
                            message: this.$tc('freepay.capture.success'),
                        });
                        this.$emit('capture-success');
                    })
                    .catch((error) => {
                        const message = error?.response?.data?.error
                            || this.$tc('freepay.capture.error');
                        this.createNotificationError({ message });
                    })
                    .finally(() => {
                        this.isLoading = false;
                    });
            } catch (error) {
                this.isLoading = false;
                this.createNotificationError({
                    message: error?.message || this.$tc('freepay.capture.error'),
                });
            }
        },
    },
});
