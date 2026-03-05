# Quick Start Guide - Freepay Payment Plugin

## Installation

### Copy to Shopware
```bash
cp -r FreepayPayment /path/to/shopware/custom/plugins/
cd /path/to/shopware
bin/console plugin:refresh
bin/console plugin:install --activate FreepayPayment
bin/console cache:clear
```

## Configuration

1. **Shopware Admin → Settings → Plugins → Freepay Payment → Configuration**
   - Enable "Sandbox Mode" for testing
   - Enter Merchant ID, API Key, Webhook Secret

2. **Activate Payment Method**
   - Go to Settings → Payment Methods
   - Find "Freepay" and toggle Active
   - Assign to Sales Channels

3. **Configure Freepay Webhook**
   - In Freepay merchant dashboard, add webhook URL:
     ```
     https://YOUR-SHOP-DOMAIN.com/freepay/webhook
     ```

4. **Set Environment (if needed)**
   - Add to `.env`: `APP_URL=https://YOUR-SHOP-DOMAIN.com`

## Testing

1. Add product to cart
2. Select "Freepay" payment method
3. Complete sandbox test payment
4. Verify order status in admin

## Go Live

1. Disable "Sandbox Mode" in plugin config
2. Update to production API credentials
3. Disable "Debug Logging"

See [README.md](README.md) for full documentation.
