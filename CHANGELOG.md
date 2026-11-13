# Changelog

## [1.5.0](https://github.com/pixonaut-app/pixonaut-for-woocommerce/compare/v1.4.0...v1.5.0) (2026-11-13)


### Features

* **contracts:** pin Pixonaut API 5.0.0 and SDK 0.39.1, and pull lost template renames ([#112](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/112)) ([91b0072](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/91b0072bcd8a985d6d5293be9f57b62e887a4ee8))
* **shop-tools:** set a variation's own setup and give it on variation reads ([#110](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/110)) ([bf73010](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/bf73010bbb0f2548e5838b9fcd2178c878c00895))


### Bug Fixes

* **admin:** name a low-resolution image by its label, never its layer key ([#107](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/107)) ([126f867](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/126f86740f77bc3f94f6e1dda5f241012042fc2b))
* **storefront:** switch variations in place when the form shows the same, and open on the chosen variation ([#108](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/108)) ([101761e](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/101761e2207745c633486b34a4f38273fccd6fd6))

## [1.4.0](https://github.com/pixonaut-app/pixonaut-for-woocommerce/compare/v1.3.0...v1.4.0) (2026-11-11)


### Features

* **admin:** put what a merchant changes first in the product panel ([#75](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/75)) ([056685c](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/056685cf3d0b328d7ed53fdf13bcd5181c67e764))
* **admin:** rework a variation's own setup like the product panel ([#81](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/81)) ([abaf4d6](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/abaf4d6c3dbe2b52ec7bf8cc9f7d727e2a2729bb))
* **admin:** say what is wrong, where, and how to fix it when a product cannot be sold ([#90](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/90)) ([b33b53e](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/b33b53eea787e4848349f3364b79555d6595d850))
* **admin:** show what the shopper sees in the product panel's views and product images ([#78](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/78)) ([56e93b8](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/56e93b847070bc0a9b430327473c5ae9bef38704))
* **admin:** warn when a print size takes an image below its recommended dpi ([#93](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/93)) ([5aff4b1](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/5aff4b10aa4865e992bcb796d8b0c472d024043c))
* **catalogue:** let an attribute and a product choose whether each value is listed as its own product ([#72](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/72)) ([1a8af5c](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/1a8af5c169420ca325e01a0105fac57d6ec3a798))
* **catalogue:** link a side's template to the studio, and move references when a template's id changes ([#77](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/77)) ([500eae7](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/500eae74c6fbcd65c995e625d491185c331526c3))
* **connection:** choose the store's print file form on the settings page ([#74](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/74)) ([e2ee2d8](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/e2ee2d839cc23ce51469c7f4d00aa76843813c46))
* keep products on a paused or unpublished template out of the shop ([#71](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/71)) ([c8ab770](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/c8ab7702e946154ef9bf90e793310d1e36a03488))
* link a product by picking the printshop's catalogue product ([#91](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/91)) ([e2dfad2](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/e2dfad293e139dcdbbc9dba721d3f83d164fa538))
* print a variation at its blank variant's area, and let it set its own size ([#73](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/73)) ([3e15525](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/3e15525e8fb9149b85bd584b29a39281d11cf3ea))
* set a print size per attribute value, and need the product's only while used ([#83](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/83)) ([0d7d8ce](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/0d7d8cebb38eb591a9f295cfc58236f34cdfc1a9))
* **shop-tools:** give product reads their validity and set a variation's print size ([#70](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/70)) ([3705a2a](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/3705a2aae42421602ff96dad5c7b9ee90b275d4a))
* **storefront:** draw each attribute value's colour or picture in its swatch ([#88](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/88)) ([254a1a5](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/254a1a5918f9d9c7b25ef69cccc63abe1216179e))
* **storefront:** give a split listing card its own title, image and from-price ([#97](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/97)) ([a80b2a5](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/a80b2a5e0b10a195d4185407e004224f2a9a2ab9))
* **storefront:** list a split product once per value in the shop, opening with that value chosen ([#85](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/85)) ([36dd7c0](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/36dd7c071d3317eb06e0b1e053fc736094a19465))


### Bug Fixes

* **catalogue:** mark the template rename exceptions as log text for Plugin Check ([#92](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/92)) ([2992f09](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/2992f090c58fa62381254b9fdb2f73dae3dd42e6))
* **ci:** give each e2e site its own database port and recover from a partial WordPress clone ([#98](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/98)) ([7a31ec4](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/7a31ec406a370df6e7a28c23734eabb943c8246c))
* **pictures:** start a drawing that waited for the lock once the drawing holding it is done ([#82](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/82)) ([457fe8b](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/457fe8bebe065ca3af4cd8170829da11eca415f6))
* **storefront:** carry a product's groups to variations on other templates ([#80](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/80)) ([2ad85df](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/2ad85df16f50e3ff5288335eed4b7cdf3b5f8631))
* **storefront:** lay out attribute rows in the designer's form the same on every theme ([#76](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/76)) ([c925a81](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/c925a817694c364e2db8307006ac02f799bd5010))
* **storefront:** show the product's images and keep the buy row in place when the designer cannot load ([#86](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/86)) ([6035e02](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/6035e02c3f8f41552d230f102b7d7d6449252f0d))
* **storefront:** switch a variation's template in the mounted designer ([#69](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/69)) ([0591800](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/05918003b12d3f9a9bf24ae91ce830329ee2ae70))


### Performance Improvements

* **ci:** run only the e2e specs a change reaches, and faster order specs ([#87](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/87)) ([ff4f5ca](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/ff4f5cad71f8281a6da530fc5c1545bbd7435391))
* **ci:** run the e2e suite on parallel tests sites beside the other gate steps ([#95](https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues/95)) ([fd2dc12](https://github.com/pixonaut-app/pixonaut-for-woocommerce/commit/fd2dc123fe8b63cf1d7a777d9b6d7b3b6c798c94))

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
