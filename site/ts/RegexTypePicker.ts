/*
 * The "Message is a regular expression" toggle on the Logs page. Switching it on opens a dialog asking which kind, and
 * cancelling that dialog (with its buttons or the Escape key) leaves things as they were. The choice is kept in a
 * hidden field, which is empty for plain text.
 */

import { Elements } from './Elements.js';

export class RegexTypePicker
{
    private readonly m_toggle: HTMLInputElement;
    private readonly m_value: HTMLInputElement;
    private readonly m_label: HTMLElement;
    private readonly m_change: HTMLButtonElement;
    private readonly m_dialog: HTMLDialogElement;
    private readonly m_select: HTMLSelectElement;
    private readonly m_infos: HTMLElement[];


    /**
     * @param root - holds the [data-role=regex-toggle], regex-value, regex-label and regex-change elements.
     * @param dialog - the dialog, with a regex-select, its regex-info panels, and confirm, cancel and dismiss buttons.
     */
    public constructor(root: HTMLElement, dialog: HTMLDialogElement)
    {
        this.m_toggle = Elements.findWithin(root, '[data-role=regex-toggle]', HTMLInputElement);
        this.m_value = Elements.findWithin(root, '[data-role=regex-value]', HTMLInputElement);
        this.m_label = Elements.findWithin(root, '[data-role=regex-label]', HTMLElement);
        this.m_change = Elements.findWithin(root, '[data-role=regex-change]', HTMLButtonElement);
        this.m_dialog = dialog;
        this.m_select = Elements.findWithin(dialog, '[data-role=regex-select]', HTMLSelectElement);
        this.m_infos = Array.from(dialog.querySelectorAll<HTMLElement>('[data-role=regex-info]'));
        const confirm = Elements.findWithin(dialog, '[data-role=confirm]', HTMLButtonElement);
        const cancel = Elements.findWithin(dialog, '[data-role=cancel]', HTMLButtonElement);
        const dismiss = Elements.findWithin(dialog, '[data-role=dismiss]', HTMLButtonElement);

        this.m_toggle.addEventListener('change', this.onToggle.bind(this));
        this.m_change.addEventListener('click', this.open.bind(this));
        this.m_select.addEventListener('change', this.showInfo.bind(this));
        confirm.addEventListener('click', this.confirm.bind(this));
        cancel.addEventListener('click', this.close.bind(this));
        dismiss.addEventListener('click', this.close.bind(this));
        this.m_dialog.addEventListener('close', this.restoreToggle.bind(this));
    }


    private onToggle(): void
    {
        if (this.m_toggle.checked)
        {
            this.open();
        }
        else
        {
            this.m_value.value = '';
            this.showChosen();
        }
    }


    private open(): void
    {
        if (this.m_value.value !== '')
        {
            this.m_select.value = this.m_value.value;
        }

        this.showInfo();
        this.m_dialog.showModal();
    }


    private showInfo(): void
    {
        for (const info of this.m_infos)
        {
            info.hidden = (info.dataset.syntax !== this.m_select.value);
        }
    }


    private confirm(): void
    {
        this.m_value.value = this.m_select.value;
        this.showChosen();
        this.close();
    }


    private close(): void
    {
        this.m_dialog.close();
        this.restoreToggle();
    }


    // However the dialog closed, the toggle shows whether a kind has actually been chosen.
    private restoreToggle(): void
    {
        this.m_toggle.checked = (this.m_value.value !== '');
    }


    private showChosen(): void
    {
        const isRegex = (this.m_value.value !== '');
        const option = this.m_select.selectedOptions[0];
        this.m_label.textContent = (isRegex && option !== undefined) ? option.text : '';
        this.m_change.hidden = !isRegex;
    }
}
