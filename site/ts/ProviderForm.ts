/*
 * The form for adding or editing a provider. When adding, choosing the type shows that type's fields and hides the
 * others', disabling them so they're neither required nor sent. Each secret's source (entered, or an environment
 * variable) shows its own input, and disables the other's.
 */


export class ProviderForm
{
    private readonly m_type: HTMLSelectElement | HTMLInputElement;
    private readonly m_typeFields: HTMLFieldSetElement[];


    public constructor(form: HTMLFormElement)
    {
        const type = form.querySelector('[data-role=type]');

        if (!(type instanceof HTMLSelectElement) && !(type instanceof HTMLInputElement))
        {
            throw new Error(`Missing element [data-role=type] within #${form.id}`);
        }

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

        const secrets = form.querySelectorAll('[data-role=secret]');

        for (let index = 0; index < secrets.length; index++)
        {
            const secret = secrets[index];

            if (secret instanceof HTMLElement)
            {
                this.setUpSecret(secret);
            }
        }

        this.m_type.addEventListener('change', this.showChosenType.bind(this));
        this.showChosenType();
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


    private setUpSecret(secret: HTMLElement): void
    {
        const sources = secret.querySelectorAll('input[data-role=source]');

        for (let index = 0; index < sources.length; index++)
        {
            const source = sources[index];

            if (source instanceof HTMLInputElement)
            {
                source.addEventListener('change', this.showSecretSource.bind(this, secret));
            }
        }

        this.showSecretSource(secret);
    }


    // Show the input for where the secret comes from, and hide and disable the other's.
    private showSecretSource(secret: HTMLElement): void
    {
        const checked = secret.querySelector('input[data-role=source]:checked');
        const chosen = (checked instanceof HTMLInputElement) ? checked.value : '';
        const panels = secret.querySelectorAll('[data-source]');

        for (let index = 0; index < panels.length; index++)
        {
            const panel = panels[index];

            if (panel instanceof HTMLElement)
            {
                const isChosen = (panel.dataset.source === chosen);
                panel.hidden = !isChosen;
                const inputs = panel.querySelectorAll('input');

                for (let inputIndex = 0; inputIndex < inputs.length; inputIndex++)
                {
                    inputs[inputIndex].disabled = !isChosen;
                }
            }
        }
    }
}
