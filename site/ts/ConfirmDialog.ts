/*
 * A dialog that asks before doing something that can't be undone, e.g. removing a provider: each of the buttons for it
 * opens the one dialog, saying what it's about, and the dialog's form posts to that button's URL. If the dialog asks
 * for a password (e.g. archiving an account), that field is focused when it opens, and emptied when it closes.
 *
 * If the dialog has a [data-role=warning], it shows the opening button's data-warning (e.g. what removing that provider
 * would do), and a button with data-refused="true" disables the dialog's [data-role=confirm] button, for something
 * that can't be done (e.g. removing the only enabled provider), so the dialog says why, rather than it failing after.
 */

import { Elements } from './Elements.js';

export class ConfirmDialog
{
    private readonly m_dialog: HTMLDialogElement;
    private readonly m_form: HTMLFormElement;
    private readonly m_name: HTMLElement;
    private readonly m_password: HTMLInputElement | null;
    private readonly m_warning: HTMLElement | null;
    private readonly m_confirm: HTMLButtonElement | null;


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
        this.m_warning = dialog.querySelector<HTMLElement>('[data-role=warning]');
        this.m_confirm = dialog.querySelector<HTMLButtonElement>('[data-role=confirm]');

        for (const button of buttons)
        {
            if (button.dataset.action === undefined || button.dataset.name === undefined)
            {
                throw new Error(`A button for #${dialog.id} is missing its data-action or data-name.`);
            }

            button.addEventListener('click', this.open.bind(this, button));
        }

        cancel.addEventListener('click', this.close.bind(this));
        this.m_dialog.addEventListener('close', this.clear.bind(this));
    }


    private open(button: HTMLButtonElement): void
    {
        const warning = button.dataset.warning ?? '';
        this.m_form.action = button.dataset.action ?? '';
        this.m_name.textContent = button.dataset.name ?? '';

        if (this.m_warning !== null)
        {
            this.m_warning.textContent = warning;
            this.m_warning.hidden = (warning === '');
        }

        if (this.m_confirm !== null)
        {
            this.m_confirm.disabled = (button.dataset.refused === 'true');
        }

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
