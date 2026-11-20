/*
 * Rows of a form that are added one at a time, e.g. rate limits, or the IP addresses an email token may be used from
 * (see ViewValueRows and ViewRateLimitFields). "Add" adds a row from the template, once the last row is filled in (and
 * its value is valid), up to the most the server reads. Each row's button removes it, or clears it, if it's the only
 * one. A row whose value has a check (data-check) is checked as it's typed, with the message shown beside it, and the
 * form can't be sent while one is wrong.
 *
 * Pasting a list of values (e.g. IP addresses, separated by commas or new lines) into such a row fills that row with
 * the first, and adds a row below it for each of the others (see PastedList), up to the most rows there can be.
 */

import { Elements } from './Elements.js';
import { PastedList } from './PastedList.js';
import { ValueChecks } from './ValueChecks.js';

export class DynamicRows
{
    private readonly m_rows: HTMLElement;
    private readonly m_template: HTMLTemplateElement;
    private readonly m_add: HTMLButtonElement;
    private readonly m_maxRows: number;
    private m_nextIndex: number;


    public constructor(root: HTMLElement)
    {
        this.m_rows = Elements.findWithin(root, '[data-role=rows]', HTMLElement);
        this.m_template = Elements.findWithin(root, '[data-role=row-template]', HTMLTemplateElement);
        this.m_add = Elements.findWithin(root, '[data-role=add-row]', HTMLButtonElement);
        this.m_maxRows = Number(Elements.getData(root, 'maxRows'));
        this.m_nextIndex = Number(Elements.getData(root, 'nextIndex'));

        for (const row of this.getRows())
        {
            this.setUpRow(row);
        }

        this.m_add.addEventListener('click', this.addRow.bind(this));
        this.updateAddButton();
    }


    /**
     * Set up every set of rows on the page.
     */
    public static createForPage(): DynamicRows[]
    {
        const created: DynamicRows[] = [];
        const roots = document.querySelectorAll('[data-role=dynamic-rows]');

        for (let index = 0; index < roots.length; index++)
        {
            const root = roots[index];

            if (root instanceof HTMLElement)
            {
                created.push(new DynamicRows(root));
            }
        }

        return created;
    }


    private addRow(): void
    {
        const row = this.insertRow(null, '');
        row.querySelector<HTMLElement>('[data-row-value]')?.focus();
        this.updateAddButton();
    }


    /**
     * Add a row from the template, with a value filled in: after another row, or at the end, if it's null.
     */
    private insertRow(after: HTMLElement | null, value: string): HTMLElement
    {
        const html = this.m_template.innerHTML.split('__INDEX__').join(String(this.m_nextIndex));
        const holder = document.createElement('div');
        this.m_nextIndex++;
        holder.innerHTML = html.trim();
        const row = holder.firstElementChild;

        if (!(row instanceof HTMLElement))
        {
            throw new Error('The row template has no row in it.');
        }

        if (after === null)
        {
            this.m_rows.appendChild(row);
        }
        else
        {
            after.after(row);
        }

        const input = row.querySelector('[data-row-value]');

        if (input instanceof HTMLInputElement)
        {
            input.value = value;
        }

        this.setUpRow(row);
        return row;
    }


    private setUpRow(row: HTMLElement): void
    {
        const remove = row.querySelector('[data-role=remove-row]');
        const value = row.querySelector('[data-row-value]');

        if (remove instanceof HTMLButtonElement)
        {
            remove.addEventListener('click', this.removeRow.bind(this, row));
        }

        if (value instanceof HTMLInputElement)
        {
            value.addEventListener('input', this.checkRow.bind(this, row, value));
            this.checkRow(row, value);
        }

        // Only a list of single values (which each have a check) is split up: not, e.g., a rate limit's number.
        if (value instanceof HTMLInputElement && value.dataset.check !== undefined)
        {
            value.addEventListener('paste', this.pasteList.bind(this, row, value));
        }
    }


    /**
     * When more than one value is pasted into a row, put the first in it (where the text was pasted, as a single
     * value would be), and each of the others in a new row below it, as far as there's room for them.
     */
    private pasteList(row: HTMLElement, input: HTMLInputElement, event: ClipboardEvent): void
    {
        const values = PastedList.split(event.clipboardData?.getData('text') ?? '');

        if (values.length > 1)
        {
            event.preventDefault();
            const [first, ...others] = values;
            const room = this.m_maxRows - this.getRows().length;
            const added = others.slice(0, Math.max(room, 0));
            let previous = row;
            input.setRangeText(first, input.selectionStart ?? 0, input.selectionEnd ?? 0, 'end');
            this.checkRow(row, input);

            for (const value of added)
            {
                previous = this.insertRow(previous, value);
            }

            if (added.length < others.length)
            {
                const left = others.length - added.length;
                const message = `There can only be ${this.m_maxRows} rows, so the last ${left} weren't added.`;
                this.showError(previous, message);
            }

            previous.querySelector<HTMLElement>('[data-row-value]')?.focus();
            this.updateAddButton();
        }
    }


    // Remove a row, or clear it if it's the only one, so there's always a row to fill in.
    private removeRow(row: HTMLElement): void
    {
        if (this.getRows().length > 1)
        {
            row.remove();
        }
        else
        {
            const value = row.querySelector('[data-row-value]');

            if (value instanceof HTMLInputElement)
            {
                value.value = '';
                this.checkRow(row, value);
            }
        }

        this.updateAddButton();
    }


    private checkRow(row: HTMLElement, value: HTMLInputElement): void
    {
        const text = value.value.trim();
        const check = value.dataset.check ?? '';
        let message = '';

        if (check === 'ip-network')
        {
            message = ValueChecks.checkIpNetwork(text);
        }
        else if (check === 'address-rule')
        {
            message = ValueChecks.checkAddressRule(text);
        }
        else if (check === 'domain')
        {
            message = ValueChecks.checkDomain(text);
        }
        else if (check === 'email-address')
        {
            message = ValueChecks.checkEmailAddress(text);
        }

        value.setCustomValidity(message);
        this.showError(row, message);
        this.updateAddButton();
    }


    // Show a message beside a row, or clear it, with an empty string.
    private showError(row: HTMLElement, message: string): void
    {
        const error = row.querySelector('[data-role=row-error]');

        if (error instanceof HTMLElement)
        {
            error.textContent = message;
        }
    }


    // Another row can only be added once the last is filled in, and valid, and while there's room for one.
    private updateAddButton(): void
    {
        const rows = this.getRows();
        const last = rows[rows.length - 1];
        const value = last?.querySelector('[data-row-value]');
        const isFilled = (value instanceof HTMLInputElement) && value.value.trim() !== '' && value.checkValidity();
        this.m_add.disabled = !isFilled || rows.length >= this.m_maxRows;
    }


    private getRows(): HTMLElement[]
    {
        const rows: HTMLElement[] = [];

        for (let index = 0; index < this.m_rows.children.length; index++)
        {
            const child = this.m_rows.children[index];

            if (child instanceof HTMLElement)
            {
                rows.push(child);
            }
        }

        return rows;
    }
}
