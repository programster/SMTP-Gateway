/*
 * A dialog that asks before doing something that can't be undone, e.g. removing a provider: each of the buttons for it
 * opens the one dialog, saying what it's about, and the dialog's form posts to that button's URL. If the dialog asks
 * for a password (e.g. archiving an account), that field is focused when it opens, and emptied when it closes.
 */

import { Elements } from './Elements.js';

export class ConfirmDialog
{
    private readonly m_dialog: HTMLDialogElement;
    private readonly m_form: HTMLFormElement;
    private readonly m_name: HTMLElement;
    private readonly m_password: HTMLInputElement | null;


    /**
     * @param dialog - the dialog, with a [data-role=form] form, a [data-role=name] to show what it's about, and a
     * [data-role=cancel] button.
     * @param buttons - the buttons that each open it, from their data-action (where the form posts) and data-name.
     */
    public constructor(dialog: HTMLDialogElement, ...buttons: HTMLButtonElement[])
    {
        this.m_dialog = dialog;
        this.m_form = Elements.findWithin(dialog, '[data-role=form]', HTMLFormElement);
        this.m_name = Elements.findWithin(dialog, '[data-role=name]', HTMLElement);
        const cancel = Elements.findWithin(dialog, '[data-role=cancel]', HTMLButtonElement);
        this.m_password = dialog.querySelector<HTMLInputElement>('input[type=password]');

        for (const button of buttons)
        {
            if (button.dataset.action === undefined || button.dataset.name === undefined)
            {
                throw new Error(`A button for #${dialog.id} is missing its data-action or data-name.`);
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
        this.m_password?.focus();
    }


    private close(): void
    {
        this.m_dialog.close();
    }


    // So a password isn't left in the dialog once it's closed.
    private clear(): void
    {
        if (this.m_password !== null)
        {
            this.m_password.value = '';
        }
    }
}
