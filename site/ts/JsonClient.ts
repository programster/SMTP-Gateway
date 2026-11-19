/*
 * Fetches from, and sends JSON (or a form) to, the web interface's JSON endpoints, with the CSRF token, and turns an
 * error response ({ "error": { "message": ..., "code": ... } }) into a thrown Error carrying its message.
 */

export class JsonClient
{
    private readonly m_csrfToken: string;


    public constructor(csrfToken: string)
    {
        this.m_csrfToken = csrfToken;
    }


    public async post(url: string, body: object): Promise<any>
    {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': this.m_csrfToken,
            },
            body: JSON.stringify(body),
        });

        return JsonClient.readResponse(response);
    }


    /**
     * Post a form's fields as the browser would (only the fields it would send, e.g. not disabled ones), asking for
     * JSON back.
     */
    public async postForm(url: string, form: HTMLFormElement): Promise<any>
    {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': this.m_csrfToken,
            },
            body: new FormData(form),
        });

        return JsonClient.readResponse(response);
    }


    public async get(url: string): Promise<any>
    {
        const response = await fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': this.m_csrfToken,
            },
        });

        return JsonClient.readResponse(response);
    }


    private static async readResponse(response: Response): Promise<any>
    {
        // E.g. the login page, after following a redirect to it, if the session has expired.
        if (!(response.headers.get('Content-Type') ?? '').includes('application/json'))
        {
            throw new Error(
                `The server didn't answer as expected (HTTP ${response.status}). If you've been logged out, log in`
                + ' again in another tab, then try again.'
            );
        }

        const data = await response.json();

        if (!response.ok)
        {
            const message = (data && data.error && data.error.message) ? data.error.message : 'Something went wrong.';
            throw new Error(message);
        }

        return data;
    }
}
