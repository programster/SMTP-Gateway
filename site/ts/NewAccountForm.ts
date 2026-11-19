/*
 * The form for adding an account: the password fields (and "make them change it") only show, and are only required,
 * when the password is to be set now, rather than chosen by its owner with an emailed link.
 */

import { Elements } from './Elements.js';

export class NewAccountForm
{
    private readonly m_sources: HTMLInputElement[];
    private readonly m_setPassword: HTMLElement;
    private readonly m_passwords: HTMLInputElement[];


    public constructor(form: HTMLFormElement)
    {
        this.m_sources = NewAccountForm.findInputs(form, '[data-role=password-source]');
        this.m_setPassword = Elements.findWithin(form, '[data-role=set-password]', HTMLElement);
        this.m_passwords = NewAccountForm.findInputs(form, '[data-role=password]');

        for (const source of this.m_sources)
        {
            source.addEventListener('change', this.update.bind(this));
        }

        this.update();
    }


    private update(): void
    {
        let isSettingNow = false;

        for (const source of this.m_sources)
        {
            isSettingNow = isSettingNow || (source.checked && source.value === 'set');
        }

        this.m_setPassword.hidden = !isSettingNow;

        for (const password of this.m_passwords)
        {
            password.required = isSettingNow;
        }
    }


    private static findInputs(root: HTMLElement, selector: string): HTMLInputElement[]
    {
        const found: HTMLInputElement[] = [];
        const elements = root.querySelectorAll(selector);

        for (let index = 0; index < elements.length; index++)
        {
            const element = elements[index];

            if (!(element instanceof HTMLInputElement))
            {
                throw new Error(`${selector} isn't an input.`);
            }

            found.push(element);
        }

        if (found.length === 0)
        {
            throw new Error(`Missing ${selector}.`);
        }

        return found;
    }
}
