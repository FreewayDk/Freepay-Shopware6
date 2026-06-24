import './extension/sw-order-detail-details';
import './component/freepay-refund-modal';
import './component/freepay-capture-modal';

import enGB from './snippet/en-GB.json';
import daDK from './snippet/da-DK.json';

Shopware.Locale.extend('en-GB', enGB);
Shopware.Locale.extend('da-DK', daDK);
