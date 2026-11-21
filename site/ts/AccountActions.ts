/*
 * The archive, delete and grace period buttons for accounts, on the users page and an account's own page: each opens
 * its dialog (see ViewAccountActionDialog), which asks for the password of whoever is doing it. A page may have any of
 * them, or none.
 */

import { ConfirmDialog } from './ConfirmDialog.js';
import { Elements } from './Elements.js';

export class AccountActions
{
    public static setUp(): void
    {
        const archiveButtons = Elements.findAllButtons('[data-role=archive-account]');
        const deleteButtons = Elements.findAllButtons('[data-role=delete-account]');
        const graceButtons = Elements.findAllButtons('[data-role=mfa-grace-account]');

        if (archiveButtons.length > 0)
        {
            new ConfirmDialog(Elements.findById('archive-account-dialog', HTMLDialogElement), ...archiveButtons);
        }

        if (deleteButtons.length > 0)
        {
            new ConfirmDialog(Elements.findById('delete-account-dialog', HTMLDialogElement), ...deleteButtons);
        }

        if (graceButtons.length > 0)
        {
            new ConfirmDialog(Elements.findById('mfa-grace-account-dialog', HTMLDialogElement), ...graceButtons);
        }
    }
}
