/*
 * Tabs: a [role=tablist] of [role=tab] buttons, each showing the [role=tabpanel] its aria-controls names and hiding
 * the others. The arrow keys, Home and End move between the tabs, as the WAI-ARIA tabs pattern describes.
 */

import { Elements } from './Elements.js';

export class Tabs
{
    private readonly m_tabs: HTMLButtonElement[];
    private readonly m_panels: HTMLElement[];


    public constructor(root: HTMLElement)
    {
        this.m_tabs = Array.from(root.querySelectorAll<HTMLButtonElement>('[role=tab]'));
        this.m_panels = [];

        if (this.m_tabs.length === 0)
        {
            throw new Error('Missing the tabs');
        }

        for (const tab of this.m_tabs)
        {
            const panelId = tab.getAttribute('aria-controls') || '';
            this.m_panels.push(Elements.findById(panelId, HTMLElement));
            tab.addEventListener('click', this.select.bind(this, tab));
            tab.addEventListener('keydown', this.moveWithKeys.bind(this, tab));
        }

        const selected = this.m_tabs.find(Tabs.isSelected) || this.m_tabs[0];
        this.select(selected);
    }


    private static isSelected(tab: HTMLButtonElement): boolean
    {
        return tab.getAttribute('aria-selected') === 'true';
    }


    private select(chosen: HTMLButtonElement): void
    {
        for (let index = 0; index < this.m_tabs.length; index++)
        {
            const tab = this.m_tabs[index];
            const isChosen = (tab === chosen);
            tab.setAttribute('aria-selected', isChosen ? 'true' : 'false');
            tab.tabIndex = isChosen ? 0 : -1;
            this.m_panels[index].hidden = !isChosen;
        }
    }


    private moveWithKeys(tab: HTMLButtonElement, event: KeyboardEvent): void
    {
        const index = this.m_tabs.indexOf(tab);
        const last = this.m_tabs.length - 1;
        let next = -1;

        if (event.key === 'ArrowRight')
        {
            next = (index === last) ? 0 : index + 1;
        }
        else if (event.key === 'ArrowLeft')
        {
            next = (index === 0) ? last : index - 1;
        }
        else if (event.key === 'Home')
        {
            next = 0;
        }
        else if (event.key === 'End')
        {
            next = last;
        }

        if (next !== -1)
        {
            event.preventDefault();
            this.select(this.m_tabs[next]);
            this.m_tabs[next].focus();
        }
    }
}
