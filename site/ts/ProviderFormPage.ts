// The page for adding or editing a provider, whose rate limits are rows added one at a time.
import { DynamicRows } from './DynamicRows.js';
import { Elements } from './Elements.js';
import { ProviderForm } from './ProviderForm.js';

new ProviderForm(Elements.findById('provider-form', HTMLFormElement));
DynamicRows.createForPage();
