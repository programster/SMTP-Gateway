# How long emails are kept

Each part of an email is kept for as long as its own setting says (under **Settings > How
long emails and logs are kept**), and each fits within the one before it:

| Setting (in `settings.yaml`'s `retention` section) | What it keeps | Values | Default |
|---|---|---|---|
| Email records (`email_record_days`) | The email's record: who it was from and to, its subject, size, status, provider and tracking events | A number of days | 90 |
| Email content (`email_content_days`) | The whole email, for downloading as a `.eml` file | Not kept (-1), as long as the record (0), or a number of days | Not kept |
| Attachments (`email_attachment_days`) | The attachments within that content | Not kept (-1), as long as the content (0), or a number of days | Not kept |

Not kept means deleted as soon as the email has been sent (or has failed, or was cancelled). As long as
the record (or content) means kept for as long as the setting above it, and a number of days counts from
when the email was sent. So with records kept for 90 days, content for 30 and attachments for 7, an email
loses its attachments after a week, the rest of its content after a month, and its record after three
months. There's no point keeping content without its record, or attachments without the rest of the
content, so a setting that's longer than the one above it allows is refused when it's saved.

An attachment is any part of the email other than its plain text and HTML body, so inline images
count. When they're removed, the rest of the email is kept as it was, with a small
`attachments-removed.txt` in their place listing each one's name, type and size. The web interface
says when they were removed, and the API gives the number as `removedAttachments`. If an email's
attachments can't be taken out on their own (e.g. the whole email is a single attachment), its whole
content is deleted instead, so attachments are never kept for longer than they should be.

Until an email has been sent, it's always kept whole, attachments included, as it's needed to send it.
So emails waiting in the queue take up space whatever these are set to.

Content is deleted by a clean up that runs every night, as are records. Attachments that aren't kept at
all are removed as soon as the email is sent or fails, and the rest within five minutes. Logs are kept
for 90 days by default, which is set on the same form. If `settings.yaml` is edited by hand so that
these settings are invalid, nothing is deleted until they're fixed.

## Disk space

Each email's record (its sender, recipients, subject, status and so on, with its indexes) takes about
1 KB, and is kept for as long as email records are. Its content (the whole email, attachments
included) is also stored until it's sent, and for longer if the retention settings say so (see
[How long emails are kept](#how-long-emails-are-kept)).
PostgreSQL
compresses it, which shrinks HTML and text to roughly a third, but does little for attachments, as
they're already compressed (and then base64-encoded). Rough estimates for 100,000 emails:

| | Size of each email | Content of 100,000 | Their records |
|---|---|---|---|
| Plain text | about 5 KB | about 0.2 GB | about 0.1 GB |
| Typical HTML (a notification or receipt) | about 30 KB | about 1 GB | about 0.1 GB |
| Heavy HTML (a newsletter) | about 100 KB | about 3 GB | about 0.1 GB |
| With a 1 MB attachment | about 1.4 MB | about 120 GB | about 0.1 GB |
| At the size limit (20 MB by default) | 20 MB | up to 2 TB | about 0.1 GB |

So with the default settings, where content is deleted once sent, emails without attachments take very
little space: what stays is about 1 KB per email until its record's time has passed. Space
adds up when content is kept (keeping it without attachments costs far less), or when emails pile up
in the queue faster than the rate limits let them out, and far faster with attachments. Whoever holds an
email token chooses how large its emails are, so a leaked token could
queue emails at the size limit at the speed of its network connection (about 1 TB a day at 100 Mbit/s).
Set `SMTP_MAX_MESSAGE_BYTES` (which applies to the API too) to the largest email your services really
send (the Settings page then suggests the memory and request size limits to go with it), and keep an
eye on the queue in the web interface.
