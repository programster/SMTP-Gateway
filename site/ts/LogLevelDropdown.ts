/*
 * The logs' Levels filter: a dropdown of checkboxes, so any number can be chosen. Shift-clicking one sets everything
 * between it and the last one clicked the same way. It closes on Escape, or a click outside it, and its closed state
 * says which levels are chosen.
 */

import { Elements } from './Elements.js';

export class LogLevelDropdown
{
    private static readonly MAX_NAMED_LEVELS = 2;

    private readonly m_dropdown: HTMLDetailsElement;
    private readonly m_summary: HTMLElement;
    private readonly m_boxes: HTMLInputElement[];
    private m_lastClicked: number;


    public constructor(dropdown: HTMLDetailsElement)
    {
        this.m_dropdown = dropdown;
        this.m_summary = Elements.findWithin(dropdown, '[data-role=level-summary]', HTMLElement);
        this.m_boxes = Array.from(dropdown.querySelectorAll<HTMLInputElement>('input[type=checkbox]'));
        this.m_lastClicked = -1;
        const self = this;

        const listenToBox = function (box: HTMLInputElement, index: number): void {
            const onClick = function (event: MouseEvent): void {
                self.handleClick(index, event.shiftKey);
            };

            box.addEventListener('click', onClick);
        };

        this.m_boxes.forEach(listenToBox);
        document.addEventListener('click', this.closeIfOutside.bind(this));
        dropdown.addEventListener('keydown', this.closeOnEscape.bind(this));
    }


    private handleClick(index: number, isRange: boolean): void
    {
        if (isRange && this.m_lastClicked >= 0)
        {
            const first = Math.min(index, this.m_lastClicked);
            const last = Math.max(index, this.m_lastClicked);
            const isChecked = this.m_boxes[index].checked;

            for (let current = first; current <= last; current++)
            {
                this.m_boxes[current].checked = isChecked;
            }
        }

        this.m_lastClicked = index;
        this.showChosen();
    }


    private showChosen(): void
    {
        const labels: string[] = [];

        for (const box of this.m_boxes)
        {
            if (box.checked)
            {
                labels.push(Elements.getData(box, 'label'));
            }
        }

        let description = `${labels.length} levels`;

        if (labels.length === 0)
        {
            description = 'All levels';
        }
        else if (labels.length <= LogLevelDropdown.MAX_NAMED_LEVELS)
        {
            description = labels.join(', ');
        }

        this.m_summary.textContent = description;
    }


    private closeIfOutside(event: MouseEvent): void
    {
        if (event.target instanceof Node && !this.m_dropdown.contains(event.target))
        {
            this.m_dropdown.open = false;
        }
    }


    private closeOnEscape(event: KeyboardEvent): void
    {
        if (event.key === 'Escape' && this.m_dropdown.open)
        {
            this.m_dropdown.open = false;
            Elements.findWithin(this.m_dropdown, 'summary', HTMLElement).focus();
        }
    }
}
