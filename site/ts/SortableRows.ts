/*
 * Rows (e.g. of a table's <tbody>) that are put in order by dragging them by a handle, as jQuery UI's "sortable"
 * does: the row follows the pointer, and the others move aside to show where it will go. It works with a mouse, a
 * finger or a pen (it uses pointer events), and from the keyboard: with the handle focused, the up and down arrow keys
 * move its row. Each row is a [data-role=sortable-row] child of the container, with a [data-role=drag-handle] button.
 */

import { Elements } from './Elements.js';

export class SortableRows
{
    private readonly m_container: HTMLElement;
    private readonly m_onChange: () => void;
    private m_dragged: HTMLElement | null = null;
    private m_grabOffset = 0;


    /**
     * @param container - the element whose children are the rows.
     * @param onChange - called whenever a row has been moved to another place.
     */
    public constructor(container: HTMLElement, onChange: () => void)
    {
        this.m_container = container;
        this.m_onChange = onChange;

        for (const row of this.getRows())
        {
            const handle = Elements.findWithin(row, '[data-role=drag-handle]', HTMLButtonElement);
            handle.addEventListener('pointerdown', this.startDrag.bind(this, row, handle));
            handle.addEventListener('pointermove', this.drag.bind(this));
            handle.addEventListener('pointerup', this.endDrag.bind(this));
            handle.addEventListener('pointercancel', this.endDrag.bind(this));
            handle.addEventListener('keydown', this.moveWithKey.bind(this, row, handle));
        }
    }


    /**
     * Get the rows, in their order now.
     */
    public getRows(): HTMLElement[]
    {
        const rows: HTMLElement[] = [];

        for (let index = 0; index < this.m_container.children.length; index++)
        {
            const child = this.m_container.children[index];

            if (child instanceof HTMLElement && child.dataset.role === 'sortable-row')
            {
                rows.push(child);
            }
        }

        return rows;
    }


    private startDrag(row: HTMLElement, handle: HTMLButtonElement, event: PointerEvent): void
    {
        if (event.button === 0)
        {
            event.preventDefault();
            handle.setPointerCapture(event.pointerId);
            this.m_dragged = row;
            this.m_grabOffset = event.clientY - row.getBoundingClientRect().top;
            row.classList.add('is-dragging');
            this.m_container.classList.add('is-sorting');
        }
    }


    // Follow the pointer, and move the row past a neighbour once the pointer is past the neighbour's middle.
    private drag(event: PointerEvent): void
    {
        const row = this.m_dragged;

        if (row !== null)
        {
            const previous = this.getSibling(row, -1);
            const next = this.getSibling(row, 1);

            if (previous !== null && event.clientY < SortableRows.getMiddle(previous))
            {
                this.moveRow(row, previous, 'before');
            }
            else if (next !== null && event.clientY > SortableRows.getMiddle(next))
            {
                this.moveRow(row, next, 'after');
            }

            // Where the row would be without being dragged, so it can be drawn where the pointer is instead.
            row.style.transform = '';
            const top = row.getBoundingClientRect().top;
            row.style.transform = `translateY(${event.clientY - this.m_grabOffset - top}px)`;
        }
    }


    private endDrag(): void
    {
        const row = this.m_dragged;

        if (row !== null)
        {
            this.m_dragged = null;
            row.style.transform = '';
            row.classList.remove('is-dragging');
            this.m_container.classList.remove('is-sorting');
        }
    }


    private moveWithKey(row: HTMLElement, handle: HTMLButtonElement, event: KeyboardEvent): void
    {
        const previous = this.getSibling(row, -1);
        const next = this.getSibling(row, 1);

        if (event.key === 'ArrowUp' && previous !== null)
        {
            event.preventDefault();
            this.moveRow(row, previous, 'before');
            handle.focus();
        }
        else if (event.key === 'ArrowDown' && next !== null)
        {
            event.preventDefault();
            this.moveRow(row, next, 'after');
            handle.focus();
        }
    }


    // Move a row before or after its neighbour, which slides into the place it leaves.
    private moveRow(row: HTMLElement, neighbour: HTMLElement, where: 'before' | 'after'): void
    {
        const neighbourTop = neighbour.getBoundingClientRect().top;

        if (where === 'before')
        {
            neighbour.before(row);
        }
        else
        {
            neighbour.after(row);
        }

        // Draw the neighbour where it was, then let it slide to where it is now.
        const shift = neighbourTop - neighbour.getBoundingClientRect().top;
        neighbour.style.transition = 'none';
        neighbour.style.transform = `translateY(${shift}px)`;
        neighbour.getBoundingClientRect();
        neighbour.style.transition = '';
        neighbour.style.transform = '';
        this.m_onChange();
    }


    private getSibling(row: HTMLElement, offset: number): HTMLElement | null
    {
        const rows = this.getRows();
        const index = rows.indexOf(row) + offset;
        return (index >= 0 && index < rows.length) ? rows[index] : null;
    }


    private static getMiddle(element: HTMLElement): number
    {
        const box = element.getBoundingClientRect();
        return box.top + (box.height / 2);
    }
}
