/*
 * The enabled toggles on the Providers page, which save as soon as they're switched. If the server refuses (e.g. it's
 * the last enabled provider), the toggle goes back and the reason is shown. Each save changes the providers' version,
 * so the page's other forms are given the new one, and the YAML editor is refreshed unless it has unsaved edits, in
 * which case it keeps its old version, so saving it is refused rather than undoing the toggle.
 */

import { Elements } from './Elements.js';
import { JsonClient } from './JsonClient.js';

interface ProviderRemovalState
{
    name: string;
    warning: string;
    isRefused: boolean;
}

interface ProviderListState
{
    version: string;
    yaml: string;
    providers: ProviderRemovalState[];
}

export class ProviderToggles
{
    private readonly m_form: HTMLFormElement;
    private readonly m_error: HTMLElement;
    private readonly m_yaml: HTMLTextAreaElement;
    private readonly m_switches: HTMLInputElement[];
    private readonly m_client: JsonClient;


    /**
     * @param rows - the element holding the providers' rows, each with a [data-role=provider-enabled] checkbox.
     * @param form - the hidden form with the CSRF token and the version, which each toggle posts.
     * @param error - where to show why a toggle was refused.
     * @param yaml - the YAML editor's textarea.
     */
    public constructor(rows: HTMLElement, form: HTMLFormElement, error: HTMLElement, yaml: HTMLTextAreaElement)
    {
        this.m_form = form;
        this.m_error = error;
        this.m_yaml = yaml;
        this.m_switches = Array.from(rows.querySelectorAll<HTMLInputElement>('[data-role=provider-enabled]'));
        const csrfToken = Elements.findWithin(form, 'input[name=csrf_token]', HTMLInputElement).value;
        this.m_client = new JsonClient(csrfToken);

        for (const toggle of this.m_switches)
        {
            toggle.addEventListener('change', this.save.bind(this, toggle));
        }
    }


    private async save(toggle: HTMLInputElement): Promise<void>
    {
        const isEnabled = toggle.checked;
        const url = `${Elements.getData(toggle, 'path')}/${isEnabled ? 'enable' : 'disable'}`;
        this.m_error.hidden = true;

        // One at a time, as each save needs the version the one before it produced.
        this.setDisabled(true);

        try
        {
            const state: ProviderListState = await this.m_client.postForm(url, this.m_form);
            toggle.closest('tr')?.classList.toggle('is-provider-off', !isEnabled);
            this.applyState(state);
        }
        catch (error)
        {
            toggle.checked = !isEnabled;
            this.m_error.textContent = (error instanceof Error) ? error.message : String(error);
            this.m_error.hidden = false;
        }

        this.setDisabled(false);
        toggle.focus();
    }


    private setDisabled(isDisabled: boolean): void
    {
        for (const toggle of this.m_switches)
        {
            toggle.disabled = isDisabled;
        }
    }


    private applyState(state: ProviderListState): void
    {
        const editorForm = this.m_yaml.form;
        const isEditorUnchanged = (this.m_yaml.value === this.m_yaml.defaultValue);

        for (const input of Array.from(document.querySelectorAll<HTMLInputElement>('input[name=version]')))
        {
            if (input.form !== editorForm || isEditorUnchanged)
            {
                input.value = state.version;
            }
        }

        if (isEditorUnchanged)
        {
            this.m_yaml.defaultValue = state.yaml;
            this.m_yaml.value = state.yaml;
        }

        for (const button of Elements.findAllButtons('[data-role=remove-provider]'))
        {
            for (const provider of state.providers)
            {
                if (provider.name === button.dataset.name)
                {
                    button.dataset.warning = provider.warning;
                    button.dataset.refused = provider.isRefused ? 'true' : 'false';
                }
            }
        }
    }
}
