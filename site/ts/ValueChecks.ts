/*
 * Checks of what's typed into a row of a list (see DynamicRows), as the server will check it, so a mistake shows next
 * to the row as it's typed. Each gives the message to show, or an empty string when the value is fine (or empty, as an
 * empty row is ignored). The server checks every value again.
 */

export class ValueChecks
{
    /**
     * Check a single IPv4 or IPv6 address, or a CIDR range of them, e.g. "10.0.0.0/8" or "2001:db8::/32".
     */
    public static checkIpNetwork(value: string): string
    {
        const [address, prefix, extra] = value.split('/');
        const isV4 = ValueChecks.isIpv4(address);
        const isV6 = !isV4 && ValueChecks.isIpv6(address);
        const maxPrefix = isV4 ? 32 : 128;
        const isPrefixValid = (prefix === undefined) || (/^[0-9]{1,3}$/.test(prefix) && Number(prefix) <= maxPrefix);
        let message = '';

        if (value === '')
        {
            message = '';
        }
        else if ((!isV4 && !isV6) || extra !== undefined)
        {
            message = 'This isn\'t an IP address, or a CIDR range such as 10.0.0.0/8.';
        }
        else if (!isPrefixValid)
        {
            message = `The range after the "/" must be from 0 to ${maxPrefix}.`;
        }

        return message;
    }


    /**
     * Check an address rule: "*@example.org" (anyone at a domain), or a single address. A "*" on its own isn't one, as
     * anyone is allowed by leaving the list empty.
     */
    public static checkAddressRule(value: string): string
    {
        const isDomain = /^\*@[^@\s*]+\.[^@\s*]+$/.test(value);
        const isAddress = /^[^@\s*]+@[^@\s*]+$/.test(value);
        let message = '';

        if (value === '*')
        {
            message = 'To allow anyone, leave the list empty.';
        }
        else if (value !== '' && !isDomain && !isAddress)
        {
            message = 'Enter *@example.org for anyone at a domain, or a single address.';
        }

        return message;
    }


    private static isIpv4(address: string): boolean
    {
        const parts = address.split('.');
        let isValid = (parts.length === 4);

        for (const part of parts)
        {
            isValid = isValid && /^[0-9]{1,3}$/.test(part) && Number(part) <= 255;
        }

        return isValid;
    }


    // The browser's own URL parser checks an IPv6 address, as it's written inside "[...]" in a URL.
    private static isIpv6(address: string): boolean
    {
        let isValid = address.includes(':') && /^[0-9a-fA-F:.]+$/.test(address);

        try
        {
            new URL(`http://[${address}]/`);
        }
        catch
        {
            isValid = false;
        }

        return isValid;
    }
}
