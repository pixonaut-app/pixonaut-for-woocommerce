import { measurePreviews } from './preview-fit';

// Printed inline after the preview (`Storefront::preview_html()`): the
// storefront script is deferred, and the preview fits under the window
// from the first paint.
measurePreviews(document);
