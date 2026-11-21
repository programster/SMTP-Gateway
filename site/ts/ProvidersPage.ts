// The providers page: dragging the providers into order, their enabled toggles, the checks of the providers' accounts
// and every active email token's allowed senders, and the dialog that asks before a provider is removed.
import { ConfirmDialog } from './ConfirmDialog.js';
import { Elements } from './Elements.js';
import { ProviderOrder } from './ProviderOrder.js';
import { ProviderToggles } from './ProviderToggles.js';
import { SenderChecksPanel } from './SenderChecksPanel.js';

const providerRows = Elements.findById('provider-rows', HTMLElement);
const orderForm = Elements.findById('provider-order', HTMLFormElement);
const toggleForm = Elements.findById('provider-toggle-form', HTMLFormElement);
const toggleError = Elements.findById('provider-toggle-error', HTMLElement);
new ProviderOrder(providerRows, orderForm);
new ProviderToggles(providerRows, toggleForm, toggleError, Elements.findById('yaml', HTMLTextAreaElement));
new SenderChecksPanel(Elements.findById('sender-checks', HTMLElement));

const removeButtons = Elements.findAllButtons('[data-role=remove-provider]');
new ConfirmDialog(Elements.findById('remove-provider-dialog', HTMLDialogElement), ...removeButtons);
