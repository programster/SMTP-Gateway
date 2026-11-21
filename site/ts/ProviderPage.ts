// A provider's own page, the dialog that asks before it's removed, and copying what to enter for its webhook.
import { ConfirmDialog } from './ConfirmDialog.js';
import { CopyButtons } from './CopyButtons.js';
import { Elements } from './Elements.js';

const removeButtons = Elements.findAllButtons('[data-role=remove-provider]');
new ConfirmDialog(Elements.findById('remove-provider-dialog', HTMLDialogElement), ...removeButtons);

// Only an SMTP2GO provider's page has these.
const webhookSteps = document.getElementById('smtp2go-webhook-steps');

if (webhookSteps !== null)
{
    new CopyButtons(webhookSteps);
}
