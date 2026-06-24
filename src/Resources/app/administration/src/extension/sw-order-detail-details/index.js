import template from './sw-order-detail-details.html.twig';
import './sw-order-detail-details.scss';

const { Component } = Shopware;

Component.override('sw-order-detail-details', {
    template,

    data() {
        return {
            freepayShowRefundModal: false,
            freepayRefunds: [],
            freepayRefundsLoading: false,
            freepayShowCaptureModal: false,
            freepayCaptures: [],
            freepayCapturesLoading: false,
            freepayCaptureSummary: {
                capturedTotal: 0,
                transactionTotal: 0,
                remaining: 0,
                currencyIso: 'EUR',
                transactionState: null,
            },
        };
    },

    computed: {
        freepayOrderId() {
            return this.$route.params.id;
        },

        freepayRemaining() {
            return Number(this.freepayCaptureSummary.remaining ?? 0);
        },

        freepayCanCapture() {
            return this.freepayRemaining > 0.0001;
        },

        freepayCaptureColumns() {
            return [
                {
                    property: 'amount',
                    label: this.$tc('freepay.capture.colAmount'),
                    align: 'right',
                },
                {
                    property: 'createdAt',
                    label: this.$tc('freepay.capture.colDate'),
                },
            ];
        },

        freepayRefundColumns() {
            return [
                {
                    property: 'amount',
                    label: this.$tc('freepay.refund.colAmount'),
                    align: 'right',
                },
                {
                    property: 'createdAt',
                    label: this.$tc('freepay.refund.colDate'),
                },
            ];
        },
    },

    created() {
        this.freepayLoadCaptures();
        this.freepayLoadRefunds();
    },

    methods: {
        freepayLoadCaptures() {
            if (!this.freepayOrderId) {
                return;
            }

            this.freepayCapturesLoading = true;

            // Backend endpoint uses raw SQL — the DAL can't filter this entity by the
            // deep association path when Elasticsearch is enabled.
            const syncService = Shopware.Service('syncService');

            syncService.httpClient
                .get(`/_action/freepay/captures/${this.freepayOrderId}`, {
                    headers: syncService.getBasicHeaders(),
                })
                .then((response) => {
                    this.freepayCaptures = response.data?.captures ?? [];
                    this.freepayCaptureSummary = {
                        capturedTotal: Number(response.data?.capturedTotal ?? 0),
                        transactionTotal: Number(response.data?.transactionTotal ?? 0),
                        remaining: Number(response.data?.remaining ?? 0),
                        currencyIso: response.data?.currencyIso ?? 'EUR',
                        transactionState: response.data?.transactionState ?? null,
                    };
                })
                .catch((error) => {
                    this.freepayCaptures = [];
                    console.error('Freepay: failed to load captures', error);
                })
                .finally(() => {
                    this.freepayCapturesLoading = false;
                });
        },

        freepayFormatSummaryAmount(value) {
            return Shopware.Utils.format.currency(
                Number(value ?? 0),
                this.freepayCaptureSummary.currencyIso ?? 'EUR'
            );
        },

        freepayFormatCaptureAmount(capture) {
            return Shopware.Utils.format.currency(
                Number(capture.amount ?? 0),
                this.freepayCaptureSummary.currencyIso ?? 'EUR'
            );
        },

        freepayOpenCaptureModal() {
            this.freepayShowCaptureModal = true;
        },

        freepayCloseCaptureModal() {
            this.freepayShowCaptureModal = false;
        },

        freepayOnCaptureSuccess() {
            this.freepayShowCaptureModal = false;

            // Reload the whole view: the payment-status badge is rendered by the order
            // page, not this card. Brief delay so the success notification is seen.
            window.setTimeout(() => {
                window.location.reload();
            }, 1200);
        },

        freepayLoadRefunds() {
            if (!this.freepayOrderId) {
                return;
            }

            this.freepayRefundsLoading = true;

            // Backend endpoint uses raw SQL — the DAL can't filter this entity by the
            // deep association path when Elasticsearch is enabled.
            const syncService = Shopware.Service('syncService');

            syncService.httpClient
                .get(`/_action/freepay/refunds/${this.freepayOrderId}`, {
                    headers: syncService.getBasicHeaders(),
                })
                .then((response) => {
                    this.freepayRefunds = response.data?.refunds ?? [];
                })
                .catch((error) => {
                    this.freepayRefunds = [];
                    console.error('Freepay: failed to load refunds', error);
                })
                .finally(() => {
                    this.freepayRefundsLoading = false;
                });
        },

        freepayFormatDate(value) {
            return value ? Shopware.Utils.format.date(value) : '';
        },

        freepayFormatAmount(refund) {
            return Shopware.Utils.format.currency(
                Number(refund.amount ?? 0),
                refund.currencyIso ?? 'EUR'
            );
        },

        freepayOpenRefundModal() {
            this.freepayShowRefundModal = true;
        },

        freepayCloseRefundModal() {
            this.freepayShowRefundModal = false;
        },

        freepayOnRefundSuccess() {
            this.freepayShowRefundModal = false;

            // The payment-status badge is rendered by the order page, not this card,
            // so reload the whole view to reflect the new status. Brief delay so the
            // success notification stays visible first.
            window.setTimeout(() => {
                window.location.reload();
            }, 1200);
        },
    },
});
