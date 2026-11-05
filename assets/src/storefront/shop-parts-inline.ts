import { followPrintedInputs } from './printed-inputs';
import { printPageParts } from './shop-parts';

// Printed inline after the add-to-cart form (`Storefront::print_parts_script()`):
// the storefront script is deferred, and the parts stand in the printed form
// from the first paint, its inputs in use.
printPageParts(document);
followPrintedInputs(document);
