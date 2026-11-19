/*
 * Converts between binary data and base64url strings, the form binary values take in the JSON sent to and from the
 * server for WebAuthn.
 */

export class Base64Url
{
    public static toBuffer(value: string): ArrayBuffer
    {
        const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
        const padded = base64 + '='.repeat((4 - (base64.length % 4)) % 4);
        const binary = atob(padded);
        const bytes = new Uint8Array(binary.length);

        for (let i = 0; i < binary.length; i++)
        {
            bytes[i] = binary.charCodeAt(i);
        }

        return bytes.buffer;
    }


    public static fromBuffer(buffer: ArrayBuffer): string
    {
        const bytes = new Uint8Array(buffer);
        let binary = '';

        for (let i = 0; i < bytes.length; i++)
        {
            binary += String.fromCharCode(bytes[i]);
        }

        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
}
