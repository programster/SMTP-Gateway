// The page shown once after creating an API key, with buttons to copy the new credentials.
import { CopyButtons } from './CopyButtons.js';
import { Elements } from './Elements.js';

new CopyButtons(Elements.findById('created-secret', HTMLElement));
