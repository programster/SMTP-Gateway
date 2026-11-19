/*
 * Buttons that copy the value of an input to the clipboard: <button data-copy-target="input-id">Copy</button>.
 */

export class CopyButtons
{
    public constructor(root: HTMLElement)
    {
        const buttons = root.querySelectorAll<HTMLButtonElement>('button[data-copy-target]');

        for (const button of Array.from(buttons))
        {
            const targetId = button.dataset.copyTarget || '';
            const target = document.getElementById(targetId);

            if (!(target instanceof HTMLInputElement))
            {
                throw new Error(`Missing input #${targetId} for a copy button`);
            }

            const copy = async function (): Promise<void> {
                await navigator.clipboard.writeText(target.value);
                button.textContent = 'Copied';
            };

            button.addEventListener('click', copy);
        }
    }
}
