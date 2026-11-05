const path = require('node:path');
const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
	...defaultConfig,
	entry: {
		storefront: './assets/src/storefront/index.ts',
		'storefront-parts': './assets/src/storefront/shop-parts-inline.ts',
		'admin-product': './assets/src/admin/product-panel.ts',
		'admin-products': './assets/src/admin/products-list.ts',
		'admin-developer-tools': './assets/src/admin/developer-tools.ts',
		'admin-settings': './assets/src/admin/settings.ts',
	},
	output: {
		...defaultConfig.output,
		path: path.resolve(__dirname, 'assets/build'),
	},
};
