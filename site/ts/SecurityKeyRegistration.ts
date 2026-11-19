/*
 * Registering a new security key (WebAuthn): gets the options from the server, has the browser create a credential
 * on the key, and sends it back to be stored. The browser then goes where the server says: the account page, to show
 * the new key, or, if it's the account's first second factor, the page showing its new backup codes.
 */

import { Base64Url } from './Base64Url.js';
import { Elements } from './Elements.js';
import { JsonClient } from './JsonClient.js';

export class SecurityKeyRegistration
{
    private readonly m_button: HTMLButtonElement;
    private readonly m_name: HTMLInputElement;
    private readonly m_error: HTMLElement;
    private readonly m_optionsUrl: string;
    private readonly m_registerUrl: string;
    private readonly m_client: JsonClient;


    public constructor(root: HTMLElement)
    {
        this.m_button = Elements.findWithin(root, '[data-role=start]', HTMLButtonElement);
        this.m_name = Elements.findWithin(root, '[data-role=name]', HTMLInputElement);
        this.m_error = Elements.findWithin(root, '[data-role=error]', HTMLElement);
        this.m_optionsUrl = Elements.getData(root, 'optionsUrl');
        this.m_registerUrl = Elements.getData(root, 'registerUrl');
        this.m_client = new JsonClient(Elements.getData(root, 'csrfToken'));
        this.m_button.addEventListener('click', this.start.bind(this));
    }


    private async start(): Promise<void>
    {
        this.m_error.hidden = true;

        if (this.m_name.value.trim() === '')
        {
            this.showError('Give the security key a name first, e.g. "Blue YubiKey".');
        }
        else
        {
            await this.register();
        }
    }


    private async register(): Promise<void>
    {
        this.m_button.disabled = true;

        try
        {
            const options = await this.m_client.post(this.m_optionsUrl, {});
            const publicKey = options.publicKey;
            publicKey.challenge = Base64Url.toBuffer(publicKey.challenge);
            publicKey.user.id = Base64Url.toBuffer(publicKey.user.id);

            for (const credential of publicKey.excludeCredentials || [])
            {
                credential.id = Base64Url.toBuffer(credential.id);
            }

            const credential = await navigator.credentials.create({ publicKey: publicKey });

            if (!(credential instanceof PublicKeyCredential))
            {
                throw new Error('No security key was registered.');
            }

            const response = credential.response as AuthenticatorAttestationResponse;

            const registered = await this.m_client.post(this.m_registerUrl, {
                name: this.m_name.value.trim(),
                clientDataJSON: Base64Url.fromBuffer(response.clientDataJSON),
                attestationObject: Base64Url.fromBuffer(response.attestationObject),
            });

            window.location.assign(String(registered.redirect));
        }
        catch (error)
        {
            this.showError((error instanceof Error) ? error.message : String(error));
            this.m_button.disabled = false;
        }
    }


    private showError(message: string): void
    {
        this.m_error.textContent = message;
        this.m_error.hidden = false;
    }
}
