import template from './freepay-refund-modal.html.twig';

const { Component, Mixin } = Shopware;

Component.register('freepay-refund-modal', {
    template,

    mixins: [
        Mixin.getByName('notification'),
    ],

    props: {
        orderId: {
            type: String,
            required: true,
        },
    },

    data() {
        return {
            amount: null,
            isLoading: false,
        };
    },

    methods: {
        onCloseModal() {
            this.$emit('modal-close');
        },

        onConfirmRefund() {
            const amount = parseFloat(this.amount);

            if (!amount || amount <= 0) {
                this.createNotificationError({
                    message: this.$tc('freepay.refund.invalidAmount'),
                });
                return;
            }

            this.isLoading = true;

            try {
                // syncService is an ApiService: it exposes the configured httpClient
                // and getBasicHeaders() (the auth headers). loginService does NOT
                // have getBasicHeaders() in Shopware 6.7.
                const syncService = Shopware.Service('syncService');

                syncService.httpClient
                    .post(
                        `/_action/freepay/refund/${this.orderId}`,
                        { amount },
                        { headers: syncService.getBasicHeaders() }
                    )
                    .then(() => {
                        this.createNotificationSuccess({
                            message: this.$tc('freepay.refund.success'),
                        });
                        this.$emit('refund-success');
                    })
                    .catch((error) => {
                        const message = error?.response?.data?.error
                            || this.$tc('freepay.refund.error');
                        this.createNotificationError({ message });
                    })
                    .finally(() => {
                        this.isLoading = false;
                    });
            } catch (error) {
                this.isLoading = false;
                this.createNotificationError({
                    message: error?.message || this.$tc('freepay.refund.error'),
                });
            }
        },
    },
});
