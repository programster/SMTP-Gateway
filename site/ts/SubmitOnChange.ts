/*
 * A form that's sent as soon as one of its fields changes, e.g. the "show archived accounts" toggle on the users page,
 * so it needs no button.
 */

export class SubmitOnChange
{
    public constructor(form: HTMLFormElement)
    {
        const submit = function (): void {
            form.requestSubmit();
        };

        form.addEventListener('change', submit);
    }
}
