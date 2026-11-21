// The users page: the archive and delete dialogs, and the form for adding an account, which only shows the password
// fields when one is set now.
import { AccountActions } from './AccountActions.js';
import { Elements } from './Elements.js';
import { NewAccountForm } from './NewAccountForm.js';

AccountActions.setUp();
new NewAccountForm(Elements.findById('new-account-form', HTMLFormElement));
