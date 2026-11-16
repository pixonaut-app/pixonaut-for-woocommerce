import { domReady } from '../shared/dom-ready';
import { followVariationSlots } from './variation-slots';

domReady(() => followVariationSlots(document));
