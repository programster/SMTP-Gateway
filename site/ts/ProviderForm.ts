/*
 * The form for adding or editing a provider. When adding, choosing the type shows that type's fields and hides the
 * others', disabling them so they're neither required nor sent. Where its webhook is to be pointed has the name added
 * to the end as it's typed.
 *
 * It's saved with fetch() rather than by leaving the page, so if the server refuses it (e.g. the name's already
 * taken), the form stays exactly as it was, with every secret still entered, and only the error is shown. The page is
 * only left once it's saved.
 */

import { Elements } from './Elements.js';
import { JsonClient } from './JsonClient.js';
import { ProviderNameField } from './ProviderNameField.js';
import { Smtp2GoLoginFields } from './Smtp2GoLoginFields.js';

export class ProviderForm
{
    private readonly m_type: HTMLSelectElement | HTMLInputElement;
    private readonly m_typeFields: HTMLFieldSetElement[];
    private readonly m_form: HTMLFormElement;
    private readonly m_name: HTMLInputElement;
    private readonly m_nameField: ProviderNameField;

    // Null when editing a provider of another type, whose form doesn't have these fields.
    private readonly m_smtp2GoLogin: Smtp2GoLoginFields | null;

    private readonly m_webhookUrls: HTMLElement[];
    private readonly m_error: HTMLElement;
    private readonly m_save: HTMLButtonElement;
    private readonly m_client: JsonClient;


    public constructor(form: HTMLFormElement)
    {
        const type = form.querySelector('[data-role=type]');

        if (!(type instanceof HTMLSelectElement) && !(type instanceof HTMLInputElement))
        {
            throw new Error(`Missing element [data-role=type] within #${form.id}`);
        }

        this.m_form = form;
        this.m_type = type;
        this.m_typeFields = [];
        const fieldsets = form.querySelectorAll('fieldset[data-provider-type]');

        for (let index = 0; index < fieldsets.length; index++)
        {
            const fieldset = fieldsets[index];

            if (fieldset instanceof HTMLFieldSetElement)
            {
                this.m_typeFields.push(fieldset);
            }
        }

        this.m_name = Elements.findWithin(form, '#provider-name', HTMLInputElement);
        this.m_nameField = new ProviderNameField(form);
        const smtp2GoFields = form.querySelector('fieldset[data-provider-type=smtp2go]');

        this.m_smtp2GoLogin = (smtp2GoFields instanceof HTMLFieldSetElement)
            ? new Smtp2GoLoginFields(smtp2GoFields)
            : null;

        this.m_error = Elements.findWithin(form, '[data-role=form-error]', HTMLElement);
        this.m_save = Elements.findWithin(form, 'button[type=submit]', HTMLButtonElement);
        const csrfToken = Elements.findWithin(form, 'input[name=csrf_token]', HTMLInputElement).value;
        this.m_client = new JsonClient(csrfToken);
        this.m_webhookUrls = [];
        const webhookUrls = form.querySelectorAll('[data-role=webhook-url]');

        for (let index = 0; index < webhookUrls.length; index++)
        {
            const webhookUrl = webhookUrls[index];

            if (webhookUrl instanceof HTMLElement)
            {
                this.m_webhookUrls.push(webhookUrl);
            }
        }

        this.m_type.addEventListener('change', this.showChosenType.bind(this));
        this.m_name.addEventListener('input', this.showWebhookUrls.bind(this));
        this.m_form.addEventListener('submit', this.save.bind(this));
        this.showChosenType();
        this.showWebhookUrls();
    }


    /**
     * Save the provider, if the name is valid and every required field is filled in, and go to where the server says
     * to next. If it can't be saved, the form is left as it is, with the error above it.
     */
    private async save(event: SubmitEvent): Promise<void>
    {
        event.preventDefault();

        if (!this.m_nameField.check())
        {
            this.m_nameField.focus();
            this.m_form.reportValidity();
        }
        else if (this.m_smtp2GoLogin !== null && !this.m_smtp2GoLogin.check())
        {
            this.showError('');
        }
        else if (this.m_form.reportValidity())
        {
            this.m_save.disabled = true;
            this.showError('');

            try
            {
                const saved = await this.m_client.postForm(this.m_form.action, this.m_form);

                if (typeof saved?.redirect !== 'string')
                {
                    throw new Error('The server didn\'t say whether it was saved. Reload the providers to check.');
                }

                window.location.assign(saved.redirect);
            }
            catch (error)
            {
                this.showError((error instanceof Error) ? error.message : String(error));
                this.m_save.disabled = false;
            }
        }
    }


    // Show an error above the form, scrolled into view, or hide it, with an empty string.
    private showError(message: string): void
    {
        this.m_error.textContent = message;
        this.m_error.hidden = (message === '');

        if (message !== '')
        {
            this.m_error.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }


    // Show the chosen type's fields, and hide and disable the others'.
    private showChosenType(): void
    {
        for (const fieldset of this.m_typeFields)
        {
            const isChosen = (fieldset.dataset.providerType === this.m_type.value);
            fieldset.hidden = !isChosen;
            fieldset.disabled = !isChosen;
        }
    }


    // Show where each type's webhook is to be pointed, ending with the name typed so far.
    private showWebhookUrls(): void
    {
        const name = this.m_name.value.trim();
        const end = (name === '') ? '<name>' : encodeURIComponent(name);

        for (const webhookUrl of this.m_webhookUrls)
        {
            webhookUrl.textContent = Elements.getData(webhookUrl, 'webhookBase') + end;
        }
    }
}
