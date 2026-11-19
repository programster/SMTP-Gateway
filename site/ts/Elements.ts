/*
 * Finds the elements a feature needs, throwing straight away (naming the element) if one is missing, rather than
 * the feature failing later.
 */

export class Elements
{
    public static findById<T extends HTMLElement>(id: string, type: { new(): T }): T
    {
        const element = document.getElementById(id);

        if (!(element instanceof type))
        {
            throw new Error(`Missing element #${id}`);
        }

        return element;
    }


    public static findWithin<T extends HTMLElement>(root: HTMLElement, selector: string, type: { new(): T }): T
    {
        const element = root.querySelector(selector);

        if (!(element instanceof type))
        {
            throw new Error(`Missing element ${selector} within #${root.id}`);
        }

        return element;
    }


    public static getData(root: HTMLElement, name: string): string
    {
        const value = root.dataset[name];

        if (value === undefined || value === '')
        {
            throw new Error(`Missing data-${name} on #${root.id}`);
        }

        return value;
    }


    /**
     * Find every button that matches a selector, e.g. "[data-role=remove-provider]". There may be none.
     */
    public static findAllButtons(selector: string): HTMLButtonElement[]
    {
        const buttons: HTMLButtonElement[] = [];
        const elements = document.querySelectorAll(selector);

        for (let index = 0; index < elements.length; index++)
        {
            const element = elements[index];

            if (element instanceof HTMLButtonElement)
            {
                buttons.push(element);
            }
        }

        return buttons;
    }
}
