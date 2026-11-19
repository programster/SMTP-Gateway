// An email token's page, with the checks of its allowed senders, and the dialog that asks before revoking it.
import { Elements } from './Elements.js';
import { RevokeEmailTokenDialog } from './RevokeEmailTokenDialog.js';
import { SenderChecksPanel } from './SenderChecksPanel.js';

new SenderChecksPanel(Elements.findById('sender-checks', HTMLElement));

new RevokeEmailTokenDialog(
    Elements.findById('revoke-email-token-dialog', HTMLDialogElement),
    Elements.findWithin(document.body, '[data-role=revoke-email-token]', HTMLButtonElement)
);
