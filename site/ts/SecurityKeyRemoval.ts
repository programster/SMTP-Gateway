/*
 * Removing a security key: each key's dustbin button opens one shared dialog, which asks for the user's password and
 * posts the form to that key's remove URL. The server checks the password, and shows the account page again with
 * what happened.
 */

import { Elements } from './Elements.js';

export class SecurityKeyRemoval
{
    private readonly m_dialog: HTMLDialogElement;
    private readonly m_form: HTMLFormElement;
    private readonly m_name: HTMLElement;
    private readonly m_password: HTMLInputElement;


    /**
     * @param dialog - the dialog that asks for the password.
     * @param buttons - the buttons that each open it, for one key, from their data-action (the key's remove URL)
     * and data-name (its name, to show).
     */
    public constructor(dialog: HTMLDialogElement, ...buttons: HTMLButtonElement[])
    {
        this.m_dialog = dialog;
        this.m_form = Elements.findWithin(dialog, '[data-role=form]', HTMLFormElement);
        this.m_name = Elements.findWithin(dialog, '[data-role=name]', HTMLElement);
        this.m_password = Elements.findWithin(dialog, 'input[type=password]', HTMLInputElement);
        const cancel = Elements.findWithin(dialog, '[data-role=cancel]', HTMLButtonElement);

        for (const button of buttons)
        {
            if (button.dataset.action === undefined || button.dataset.name === undefined)
            {
                throw new Error('A remove security key button is missing its data-action or data-name.');
            }

            button.addEventListener('click', this.open.bind(this, button.dataset.action, button.dataset.name));
        }

        cancel.addEventListener('click', this.close.bind(this));
        this.m_dialog.addEventListener('close', this.clear.bind(this));
    }


    private open(action: string, name: string): void
    {
        this.m_form.action = action;
        this.m_name.textContent = name;
        this.m_dialog.showModal();
        this.m_password.focus();
    }


    private close(): void
    {
        this.m_dialog.close();
    }


    // So the password isn't left in the dialog once it's closed.
    private clear(): void
    {
        this.m_password.value = '';
    }
}
