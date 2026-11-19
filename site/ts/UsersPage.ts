// The users page: the archive and delete dialogs, the "show archived accounts" toggle, and the form for adding an
// account, which only shows the password fields when one is set now.
import { AccountActions } from './AccountActions.js';
import { Elements } from './Elements.js';
import { NewAccountForm } from './NewAccountForm.js';
import { SubmitOnChange } from './SubmitOnChange.js';

AccountActions.setUp();
new SubmitOnChange(Elements.findById('archived-filter', HTMLFormElement));
new NewAccountForm(Elements.findById('new-account-form', HTMLFormElement));
