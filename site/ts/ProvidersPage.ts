// The providers page: dragging the enabled providers into order, the checks of the providers' accounts and every
// active email token's allowed senders, and the dialog that asks before a provider is removed.
import { ConfirmDialog } from './ConfirmDialog.js';
import { Elements } from './Elements.js';
import { ProviderOrder } from './ProviderOrder.js';
import { SenderChecksPanel } from './SenderChecksPanel.js';

const orderForm = Elements.findById('provider-order', HTMLFormElement);
new ProviderOrder(Elements.findById('enabled-providers', HTMLElement), orderForm);
new SenderChecksPanel(Elements.findById('sender-checks', HTMLElement));

const removeButtons = Elements.findAllButtons('[data-role=remove-provider]');
new ConfirmDialog(Elements.findById('remove-provider-dialog', HTMLDialogElement), ...removeButtons);
