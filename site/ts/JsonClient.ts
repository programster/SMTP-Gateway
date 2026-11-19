/*
 * Fetches from, and sends JSON to, the web interface's JSON endpoints, with the CSRF token, and turns an error
 * response ({ "error": { "message": ..., "code": ... } }) into a thrown Error carrying its message.
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
        const data = await response.json();

        if (!response.ok)
        {
            const message = (data && data.error && data.error.message) ? data.error.message : 'Something went wrong.';
            throw new Error(message);
        }

        return data;
    }
}
