/*
 * Logging in with a security key (WebAuthn): gets a challenge from the server, has the key sign it, and sends the
 * signature back. On success, the server says where to go next.
 */

import { Base64Url } from './Base64Url.js';
import { Elements } from './Elements.js';
import { JsonClient } from './JsonClient.js';

export class SecurityKeyLogin
{
    private readonly m_button: HTMLButtonElement;
    private readonly m_error: HTMLElement;
    private readonly m_optionsUrl: string;
    private readonly m_verifyUrl: string;
    private readonly m_client: JsonClient;


    public constructor(root: HTMLElement)
    {
        this.m_button = Elements.findWithin(root, '[data-role=start]', HTMLButtonElement);
        this.m_error = Elements.findWithin(root, '[data-role=error]', HTMLElement);
        this.m_optionsUrl = Elements.getData(root, 'optionsUrl');
        this.m_verifyUrl = Elements.getData(root, 'verifyUrl');
        this.m_client = new JsonClient(Elements.getData(root, 'csrfToken'));
        this.m_button.addEventListener('click', this.start.bind(this));
    }


    private async start(): Promise<void>
    {
        this.m_button.disabled = true;
        this.m_error.hidden = true;

        try
        {
            const options = await this.m_client.post(this.m_optionsUrl, {});
            const publicKey = options.publicKey;
            publicKey.challenge = Base64Url.toBuffer(publicKey.challenge);

            for (const credential of publicKey.allowCredentials || [])
            {
                credential.id = Base64Url.toBuffer(credential.id);
            }

            const assertion = await navigator.credentials.get({ publicKey: publicKey });

            if (!(assertion instanceof PublicKeyCredential))
            {
                throw new Error('No security key was used.');
            }

            const response = assertion.response as AuthenticatorAssertionResponse;

            const result = await this.m_client.post(this.m_verifyUrl, {
                id: Base64Url.fromBuffer(assertion.rawId),
                clientDataJSON: Base64Url.fromBuffer(response.clientDataJSON),
                authenticatorData: Base64Url.fromBuffer(response.authenticatorData),
                signature: Base64Url.fromBuffer(response.signature),
            });

            window.location.href = result.redirect;
        }
        catch (error)
        {
            this.m_error.textContent = (error instanceof Error) ? error.message : String(error);
            this.m_error.hidden = false;
            this.m_button.disabled = false;
        }
    }
}
