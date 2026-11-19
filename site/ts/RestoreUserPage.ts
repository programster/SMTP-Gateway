// Restoring an archived account: its form only shows the password fields when one is set now.
import { Elements } from './Elements.js';
import { NewAccountForm } from './NewAccountForm.js';

new NewAccountForm(Elements.findById('restore-account-form', HTMLFormElement));
