// An account's own page: the archive, delete and grace period dialogs, and copying the recovery commands.
import { AccountActions } from './AccountActions.js';
import { CopyButtons } from './CopyButtons.js';

AccountActions.setUp();
const recovery = document.getElementById('account-recovery');

if (recovery !== null)
{
    new CopyButtons(recovery);
}
