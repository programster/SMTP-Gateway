/*
 * The choice at the start of an SMTP2GO provider's form: an API key, or an SMTP login. Only the chosen way's fields and
 * hints are shown, and the others are disabled, so they're neither required nor sent. An API key is required when it's
 * what connects, unless one is already saved.
 */

import { Elements } from './Elements.js';

export class Smtp2GoConnectionChoice
{
    private readonly m_fieldset: HTMLFieldSetElement;
    private readonly m_apiKey: HTMLInputElement;


    public constructor(fieldset: HTMLFieldSetElement)
    {
        this.m_fieldset = fieldset;
        this.m_apiKey = Elements.findWithin(fieldset, '#smtp2go_api_key', HTMLInputElement);
        const choices = fieldset.querySelectorAll<HTMLInputElement>('input[name=smtp2go_connection]');

        if (choices.length === 0)
        {
            throw new Error('Missing input[name=smtp2go_connection]');
        }

        const showChosen = this.showChosen.bind(this);

        for (const choice of Array.from(choices))
        {
            choice.addEventListener('change', showChosen);
        }

        this.showChosen();
    }


    private showChosen(): void
    {
        const chosen = this.m_fieldset.querySelector<HTMLInputElement>('input[name=smtp2go_connection]:checked');

        if (chosen === null)
        {
            throw new Error('No way of connecting is chosen');
        }

        const parts = this.m_fieldset.querySelectorAll<HTMLElement>('[data-smtp2go-connection]');

        for (const part of Array.from(parts))
        {
            const isShown = (part.dataset.smtp2goConnection === chosen.value);
            part.hidden = !isShown;
            const controls = part.querySelectorAll<HTMLInputElement | HTMLButtonElement>('input, button');

            for (const control of Array.from(controls))
            {
                control.disabled = !isShown;
            }
        }

        this.m_apiKey.required = (chosen.value === 'api_key' && this.m_apiKey.dataset.isSaved !== '1');
    }
}
