# Changelog

## [1.3.0](https://github.com/pixonaut-app/pixonaut-for-woocommerce/compare/v1.2.0...v1.3.0) (2026-11-10)


### Features

* list only the blank variants on sale, retired ones behind a toggle ([#45](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/45)) ([08a3988](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/08a39883e4de71e7b1e4dd531cdafeea2af3c7b6))
* set which blank variant each variation is printed as from a shop tool ([#40](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/40)) ([895b3e7](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/895b3e70a8c1b05158574a6853eb06b380815a27))
* **shop-tools:** reach a product's showcase pictures from the shop-tool route ([#43](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/43)) ([78bc4be](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/78bc4be441886ee4bff65b9f89e69f29d4cd2e3f))


### Bug Fixes

* **api:** read Pixonaut's answers through typed models that survive a removed field ([#42](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/42)) ([680122e](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/680122ef4071d315dae1def12ea25053fec46e0f))
* **env:** raise PHP's memory limit with the page builders installed ([#46](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/46)) ([b726528](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/b726528f8ae158f8929f417ca2790cf7ccfbfd4b))
* **sdk:** move to Pixonaut API 3.0.0, SDK 0.31.0 and pixonaut-php 3.0.0 ([#47](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/47)) ([cadab38](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/cadab38966546a78415ab8012a28fa12a0f2fbaf))
* **storefront:** drop WooCommerce's label from an attribute in its own step ([#39](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/39)) ([6aec240](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/6aec240a4eaa7a86e7a745803cb0b33fd6d86ebc))

## [1.2.0](https://github.com/pixonaut-app/pixonaut-for-woocommerce/compare/v1.1.3...v1.2.0) (2026-11-09)


### Features

* choose the view and photo each product image is drawn on ([#31](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/31)) ([6fef7d2](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/6fef7d26809575f032f048d7ffdb2be8a38cb90f))


### Bug Fixes

* **sdk:** move to Pixonaut SDK 0.30.0, which draws a canvas's fold lines ([#34](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/34)) ([6947047](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/69470479f14a2f3f70926e207d38edb2bef02585))

## [1.1.3](https://github.com/pixonaut-app/pixonaut-for-woocommerce/compare/v1.1.2...v1.1.3) (2026-11-09)


### Bug Fixes

* **catalogue:** read blanks Pixonaut answers without a photo source ([#32](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/32)) ([db74a23](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/db74a238841a8f46065fa9acb892aa82448ad738))
* **sdk:** move to Pixonaut SDK 0.28.0, which tells what an embroidery machine cannot stitch ([#27](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/27)) ([23f00d8](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/23f00d8c9d9e7f7991f86dec3a33d405d0db8a62))
* **sdk:** move to Pixonaut SDK 0.29.0, which no longer draws an estimate note under a scene ([#28](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/28)) ([b7f0709](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/b7f0709fc58f2d56689908ca6e9d01edb8aafe4d))
* **sdk:** move to Pixonaut SDK 0.29.1, which shows a drawn view again at once when switching back ([#29](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/29)) ([5b31ce6](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/5b31ce6bbf20972d297e34fb4756468b9e2a5ae3))

## [1.1.2](https://github.com/pixonaut-app/pixonaut-for-woocommerce/compare/v1.1.1...v1.1.2) (2026-11-07)


### Bug Fixes

* **sdk:** move to Pixonaut SDK 0.27.1, which draws stock scenes as signed off ([#23](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/23)) ([0106967](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/0106967fb9257b3f8ade41f852c7c4f08eb9ffee))

## [1.1.1](https://github.com/pixonaut-app/pixonaut-for-woocommerce/compare/v1.1.0...v1.1.1) (2026-11-06)


### Bug Fixes

* leave Tested up to out of the wordpress.org build's plugin header ([#24](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/24)) ([98b4dc3](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/98b4dc3cd6b2880c2a3daab1b4be544c27bcb5d7))

## [1.1.0](https://github.com/pixonaut-app/pixonaut-for-woocommerce/compare/v1.0.0...v1.1.0) (2026-11-06)


### Features

* **storefront:** move to Pixonaut SDK 0.26.0, which orders a blank's views and scenes as one list ([#15](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/15)) ([9d9229f](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/9d9229f30e2df07b8c655ba9f3891f79129fc66b))

## [1.0.0](https://github.com/pixonaut-app/pixonaut-for-woocommerce/compare/v1.0.0...v1.0.0) (2026-11-06)


### Features

* Pixonaut for WooCommerce ([ac2c525](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/ac2c525d9a5df0298034fe1b2c16cca5ce3c6ac9))
* **storefront:** add to the cart after approve again, on the customer's own trip only ([#7](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/7)) ([ad3434b](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/ad3434b157bf8ba5682f42beae6efa2287edb0e2))

## Changelog
