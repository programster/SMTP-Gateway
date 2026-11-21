/*
 * The SMTP username and password of an SMTP2GO provider, which are optional (it can use an API key instead), but only
 * work as a pair. If only one is given, the other is highlighted, with an error under it. A password that's already
 * saved counts as given, and clearing the username with the password left blank stops using the login.
 */

import { Elements } from './Elements.js';

export class Smtp2GoLoginFields
{
    private readonly m_fieldset: HTMLFieldSetElement;
    private readonly m_username: HTMLInputElement;
    private readonly m_password: HTMLInputElement;
    private readonly m_usernameError: HTMLElement;
    private readonly m_passwordError: HTMLElement;


    public constructor(fieldset: HTMLFieldSetElement)
    {
        this.m_fieldset = fieldset;
        this.m_username = Elements.findWithin(fieldset, '#smtp2go_smtp_username', HTMLInputElement);
        this.m_password = Elements.findWithin(fieldset, '#smtp2go_smtp_password', HTMLInputElement);
        this.m_usernameError = Elements.findWithin(fieldset, '#smtp2go_smtp_username-error', HTMLElement);
        this.m_passwordError = Elements.findWithin(fieldset, '#smtp2go_smtp_password-error', HTMLElement);
        this.m_username.setAttribute('aria-describedby', this.m_usernameError.id);
        this.m_password.setAttribute('aria-describedby', this.m_passwordError.id);
        const clearErrors = this.clearErrors.bind(this);
        this.m_username.addEventListener('input', clearErrors);
        this.m_password.addEventListener('input', clearErrors);
    }


    /**
     * Check the login is whole, or not given at all, highlighting and focusing the missing half if it isn't. A
     * provider of another type is always fine, as these fields are disabled and aren't sent.
     */
    public check(): boolean
    {
        this.clearErrors();
        const hasUsername = (this.m_username.value.trim() !== '');
        const hasEnteredPassword = (this.m_password.value !== '');
        const hasSavedPassword = (this.m_password.dataset.isSaved === '1');
        const isInUse = (!this.m_fieldset.disabled && !this.m_username.disabled);
        let isValid = true;

        if (isInUse && hasUsername && !hasEnteredPassword && !hasSavedPassword)
        {
            this.showError(this.m_password, this.m_passwordError, 'Enter the password for this SMTP username.');
            isValid = false;
        }
        else if (isInUse && !hasUsername && hasEnteredPassword)
        {
            this.showError(this.m_username, this.m_usernameError, 'Enter the username for this SMTP password.');
            isValid = false;
        }

        return isValid;
    }


    private showError(input: HTMLInputElement, error: HTMLElement, message: string): void
    {
        input.setAttribute('aria-invalid', 'true');
        error.textContent = message;
        error.hidden = false;
        input.focus();
    }


    private clearErrors(): void
    {
        this.m_username.removeAttribute('aria-invalid');
        this.m_password.removeAttribute('aria-invalid');
        this.m_usernameError.textContent = '';
        this.m_passwordError.textContent = '';
        this.m_usernameError.hidden = true;
        this.m_passwordError.hidden = true;
    }
}
