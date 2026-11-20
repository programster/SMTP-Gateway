/*
 * The curl command that would create what a form describes through the management API, kept up to date as the form
 * is filled in, e.g. under the form for creating an email token. The API key isn't known, so it's a placeholder. The
 * form's fields are read as the server reads them (see PolicyInput), and the empty ones left out.
 */

import { Elements } from './Elements.js';

export class ApiCommandPreview
{
    private readonly m_form: HTMLFormElement;
    private readonly m_command: HTMLElement;
    private readonly m_siteUrl: string;


    /**
     * @param form - the form for creating an email token.
     * @param preview - the preview, with a [data-role=command] to show the command in, a [data-role=copy] button,
     * and the site's address as data-site-url.
     */
    public constructor(form: HTMLFormElement, preview: HTMLElement)
    {
        this.m_form = form;
        this.m_command = Elements.findWithin(preview, '[data-role=command]', HTMLElement);
        this.m_siteUrl = Elements.getData(preview, 'siteUrl');
        const copy = Elements.findWithin(preview, '[data-role=copy]', HTMLButtonElement);
        const update = this.update.bind(this);

        form.addEventListener('input', update);
        form.addEventListener('change', update);

        // Rows are added and removed without an input event (see DynamicRows), so those changes are watched for too.
        new MutationObserver(update).observe(form, { childList: true, subtree: true });
        copy.addEventListener('click', this.copy.bind(this, copy));
        this.update();
    }


    /**
     * Get the API's request body for an email token, from the form's fields.
     */
    public static buildBody(fields: FormData): Record<string, unknown>
    {
        const body: Record<string, unknown> = { name: String(fields.get('name') ?? '') };
        const conditions: Record<string, unknown>[] = [];
        const ips = ApiCommandPreview.readRows(fields, 'allowed_ips');

        if (ips.length > 0)
        {
            conditions.push({ field: 'clientIp', allowed: ips });
        }

        const addressLists = [
            { field: 'sender', prefix: 'allowed_senders' },
            { field: 'replyTo', prefix: 'allowed_reply_tos' },
            { field: 'recipient', prefix: 'allowed_recipients' },
        ];

        for (const list of addressLists)
        {
            const rules = ApiCommandPreview.readRules(fields, list.prefix);

            if (rules.length > 0)
            {
                conditions.push({ field: list.field, allowed: rules });
            }
        }

        // The providers it's restricted to, by their ids, from the ticked boxes' names ("allowed_provider_<id>").
        if (fields.get('restrict_providers') === '1')
        {
            const prefix = 'allowed_provider_';
            const providerIds: string[] = [];

            const collectProviderId = function (value: FormDataEntryValue, name: string): void {
                if (name.startsWith(prefix) && value === '1')
                {
                    providerIds.push(name.substring(prefix.length));
                }
            };

            fields.forEach(collectProviderId);
            conditions.push({ field: 'provider', allowed: providerIds });
        }

        if (conditions.length > 0)
        {
            body.conditions = conditions;
        }

        const rateLimits = ApiCommandPreview.readRateLimits(fields);

        if (rateLimits.length > 0)
        {
            body.rateLimits = rateLimits;
        }

        const priority = Number(fields.get('priority') ?? '');

        if (Number.isInteger(priority) && priority > 0)
        {
            body.priority = priority;
        }

        return body;
    }


    /**
     * Get the curl command that sends a request body to create an email token.
     */
    public static buildCommand(body: Record<string, unknown>, siteUrl: string): string
    {
        // The JSON goes in single quotes, so a single quote in it ends them, is escaped, and starts them again.
        const json = JSON.stringify(body, null, 2).split("'").join("'\\''").split('\n').join('\n  ');

        const lines = [
            'curl \\',
            '  --request POST \\',
            "  --header 'Authorization: Bearer <your API key>' \\",
            "  --header 'Content-Type: application/json' \\",
            `  --data '${json}' \\`,
            `  ${siteUrl}/api/v1/email-tokens`,
        ];

        return lines.join('\n');
    }


    private update(): void
    {
        const body = ApiCommandPreview.buildBody(new FormData(this.m_form));
        this.m_command.textContent = ApiCommandPreview.buildCommand(body, this.m_siteUrl);
    }


    private async copy(button: HTMLButtonElement): Promise<void>
    {
        await navigator.clipboard.writeText(this.m_command.textContent ?? '');
        button.textContent = 'Copied';
    }


    // The values of a list's rows ("<prefix>_0", "<prefix>_1" and so on), in order, leaving out the empty ones.
    private static readRows(fields: FormData, prefix: string): string[]
    {
        const byIndex = new Map<number, string>();

        const read = function (value: FormDataEntryValue, key: string): void {
            const match = new RegExp(`^${prefix}_(\\d+)$`).exec(key);

            if (match !== null && String(value).trim() !== '')
            {
                byIndex.set(Number(match[1]), String(value).trim());
            }
        };

        fields.forEach(read);
        const indexes = Array.from(byIndex.keys()).sort(ApiCommandPreview.compareNumbers);
        const values: string[] = [];

        for (const index of indexes)
        {
            values.push(byIndex.get(index) ?? '');
        }

        return values;
    }


    // A list of address rules, as the API takes them: "*@example.org" a domain, or an address.
    private static readRules(fields: FormData, prefix: string): Record<string, string>[]
    {
        const rules: Record<string, string>[] = [];

        for (const value of ApiCommandPreview.readRows(fields, prefix))
        {
            if (value.startsWith('*@'))
            {
                rules.push({ type: 'domain', value: value.substring(2) });
            }
            else
            {
                rules.push({ type: 'address', value: value });
            }
        }

        return rules;
    }


    // The rate limits' rows, each with a number, as the API takes them. A row without a number is no limit.
    private static readRateLimits(fields: FormData): Record<string, string | number>[]
    {
        const limits: Record<string, string | number>[] = [];
        const maxes = ApiCommandPreview.readRows(fields, 'rate_limit_max');

        const readLimit = function (value: FormDataEntryValue, key: string): void {
            const match = /^rate_limit_max_(\d+)$/.exec(key);
            const max = Number(value);

            if (match !== null && String(value).trim() !== '' && Number.isInteger(max))
            {
                limits.push({
                    unit: String(fields.get(`rate_limit_unit_${match[1]}`) ?? ''),
                    max: max,
                    periodSeconds: Number(fields.get(`rate_limit_period_${match[1]}`) ?? 0),
                });
            }
        };

        if (maxes.length > 0)
        {
            fields.forEach(readLimit);
        }

        return limits;
    }


    private static compareNumbers(a: number, b: number): number
    {
        return a - b;
    }
}
