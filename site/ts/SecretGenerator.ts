/*
 * "Generate" buttons next to a secret on a form: <button data-role="generate-secret" data-generate-target="input-id">.
 * It fills the input with a random value in the browser, and shows it, with its copy button, so it can be pasted into
 * the other service before the form is saved.
 */

export class SecretGenerator
{
    private static readonly NUM_BYTES = 24;


    public constructor(root: HTMLElement)
    {
        const buttons = root.querySelectorAll<HTMLButtonElement>('button[data-role=generate-secret]');

        for (const button of Array.from(buttons))
        {
            const targetId = button.dataset.generateTarget || '';
            const input = document.getElementById(targetId);
            const copyButton = root.querySelector(`button[data-copy-target="${targetId}"]`);

            if (!(input instanceof HTMLInputElement) || !(copyButton instanceof HTMLButtonElement))
            {
                throw new Error(`Missing input or copy button #${targetId} for a generate button`);
            }

            const generate = function (): void {
                input.value = SecretGenerator.createSecret();
                input.type = 'text';
                input.dispatchEvent(new Event('input', { bubbles: true }));
                copyButton.hidden = false;
                copyButton.textContent = 'Copy';
                button.textContent = 'Generate again';
            };

            button.addEventListener('click', generate);
        }
    }


    // Create a random secret, as lowercase hex.
    private static createSecret(): string
    {
        const bytes = crypto.getRandomValues(new Uint8Array(SecretGenerator.NUM_BYTES));
        let secret = '';

        for (const byte of Array.from(bytes))
        {
            secret += byte.toString(16).padStart(2, '0');
        }

        return secret;
    }
}
