/*
 * The order of the enabled providers on the Providers page, which they're tried in for the overflow and fallback
 * modes: their rows are dragged into another order (see SortableRows), and once it has changed, a bar appears to save
 * it, which posts each provider's name, in order, as "order_0", "order_1" and so on (or to undo it).
 */

import { Elements } from './Elements.js';
import { SortableRows } from './SortableRows.js';

export class ProviderOrder
{
    private readonly m_form: HTMLFormElement;
    private readonly m_names: HTMLElement;
    private readonly m_rows: SortableRows;
    private readonly m_originalOrder: string;


    /**
     * @param rows - the element whose rows are the enabled providers, each with its name as data-name.
     * @param form - the form that saves the order, with a [data-role=names] element to put the names in.
     */
    public constructor(rows: HTMLElement, form: HTMLFormElement)
    {
        this.m_form = form;
        this.m_names = Elements.findWithin(form, '[data-role=names]', HTMLElement);
        this.m_rows = new SortableRows(rows, this.update.bind(this));
        this.m_originalOrder = this.getNames().join('\n');
    }


    // Show the bar, with the names in their new order, or hide it, if they're back in their original order.
    private update(): void
    {
        const names = this.getNames();
        this.m_form.hidden = (names.join('\n') === this.m_originalOrder);
        this.m_names.replaceChildren();

        for (let index = 0; index < names.length; index++)
        {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `order_${index}`;
            input.value = names[index];
            this.m_names.appendChild(input);
        }
    }


    private getNames(): string[]
    {
        const names: string[] = [];

        for (const row of this.m_rows.getRows())
        {
            names.push(Elements.getData(row, 'name'));
        }

        return names;
    }
}
