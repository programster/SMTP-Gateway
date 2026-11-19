// A provider's own page, and the dialog that asks before it's removed.
import { ConfirmDialog } from './ConfirmDialog.js';
import { Elements } from './Elements.js';

const removeButtons = Elements.findAllButtons('[data-role=remove-provider]');
new ConfirmDialog(Elements.findById('remove-provider-dialog', HTMLDialogElement), ...removeButtons);
