/*
 * The provider form's name field, checked as it's typed with the same rules as the server (ProviderDefinition), so a
 * mistake such as a capital letter is marked on the field straight away, saying what's wrong with it, rather than
 * only being found when the form is saved. The server checks the name again.
 */

import { Elements } from './Elements.js';

export class ProviderNameField
{
    // The same as ProviderDefinition::NAME_PATTERN.
    private static readonly PATTERN = /^[a-z0-9][a-z0-9_-]{0,49}$/;
    private static readonly MAX_LENGTH = 50;

    private readonly m_input: HTMLInputElement;
    private readonly m_error: HTMLElement;
    private readonly m_reservedNames: string[];


    public constructor(form: HTMLFormElement)
    {
        this.m_input = Elements.findWithin(form, '#provider-name', HTMLInputElement);
        this.m_error = Elements.findWithin(form, '[data-role=name-error]', HTMLElement);
        const reservedNames = JSON.parse(Elements.getData(this.m_input, 'reservedNames'));

        if (!Array.isArray(reservedNames))
        {
            throw new Error('data-reserved-names on #provider-name must be a JSON list of names.');
        }

        this.m_reservedNames = reservedNames.map(String);
        this.m_input.addEventListener('input', this.check.bind(this));
        this.check();
    }


    /**
     * Check the name, marking the field and saying what's wrong with it if it isn't valid, or clearing that if it is.
     * An empty name isn't marked (it's only required when the form is saved). Returns whether it's valid.
     */
    public check(): boolean
    {
        const name = this.m_input.value;
        const problem = (name === '') ? '' : this.describeProblem(name);
        this.m_input.setCustomValidity(problem);
        this.m_error.textContent = problem;
        this.m_error.hidden = (problem === '');

        if (problem === '')
        {
            this.m_input.removeAttribute('aria-invalid');
        }
        else
        {
            this.m_input.setAttribute('aria-invalid', 'true');
        }

        return (problem === '' && name !== '');
    }


    public focus(): void
    {
        this.m_input.focus();
    }


    /**
     * Say what's wrong with a name, and suggest one that would do, or give an empty string if nothing is.
     */
    private describeProblem(name: string): string
    {
        let message = '';

        if (this.m_reservedNames.includes(name))
        {
            message = `"${name}" can't be used, as the web interface uses /providers/${name} for a page.`;
        }
        else if (!ProviderNameField.PATTERN.test(name))
        {
            const reasons = ProviderNameField.findReasons(name);
            const suggestion = this.suggestName(name);
            const tryInstead = (suggestion === '') ? '' : ` Try "${suggestion}".`;

            message =
                `This isn't a valid name, as ${reasons.join(', and ')}. It must be from 1 to 50 lowercase letters,`
                + ` digits, "-" and "_", starting with a letter or digit.${tryInstead}`;
        }

        return message;
    }


    // Find each reason a name doesn't fit the pattern, e.g. "it has capital letters".
    private static findReasons(name: string): string[]
    {
        const reasons: string[] = [];
        const others = new Set<string>();

        for (const character of name)
        {
            if (!/[A-Za-z0-9_\s-]/.test(character))
            {
                others.add(`"${character}"`);
            }
        }

        if (/[A-Z]/.test(name))
        {
            reasons.push('it has capital letters');
        }

        if (/\s/.test(name))
        {
            reasons.push('it has spaces');
        }

        if (others.size > 0)
        {
            reasons.push(`it has ${Array.from(others).join(' ')}, which can't be used`);
        }

        if (/^[-_]/.test(name))
        {
            reasons.push('it starts with "-" or "_"');
        }

        if (name.length > ProviderNameField.MAX_LENGTH)
        {
            reasons.push(`it's ${name.length} characters long`);
        }

        return reasons;
    }


    // Suggest a valid name like the one given, e.g. "sendgrid-main" for "SendGrid Main", or an empty string if none.
    private suggestName(name: string): string
    {
        const suggestion = name
            .trim()
            .toLowerCase()
            .replace(/\s+/g, '-')
            .replace(/[^a-z0-9_-]/g, '')
            .replace(/^[-_]+/, '')
            .slice(0, ProviderNameField.MAX_LENGTH);

        const isUsable = (
               suggestion !== name
            && ProviderNameField.PATTERN.test(suggestion)
            && !this.m_reservedNames.includes(suggestion)
        );

        return isUsable ? suggestion : '';
    }
}
