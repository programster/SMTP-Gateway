/*
 * Splits text pasted into a row of a list (see DynamicRows) into its values, so a list copied from elsewhere, e.g.
 * "10.0.0.0/8, 192.168.1.5" or one address per line, becomes a row for each. Values are separated by commas,
 * semicolons or whitespace (spaces, tabs and new lines), none of which can be part of an IP address, a CIDR range or
 * an email address rule.
 */

export class PastedList
{
    /**
     * Split pasted text into its values, leaving out empty ones, e.g. from a trailing comma or a blank line.
     */
    public static split(text: string): string[]
    {
        const values: string[] = [];

        for (const part of text.split(/[\s,;]+/))
        {
            if (part !== '')
            {
                values.push(part);
            }
        }

        return values;
    }
}
