<?php

/*
 * POST /api/v1/send: send an email described in JSON, authenticated with an email token as a bearer token
 * ("Authorization: Bearer et_...", the same token as the SMTP password). The email is built with PHPMailer, and
 * then goes through exactly the same checks, rate limits and queue as one sent through SMTP.
 *
 * Responds 201 if the email was sent straight away, or 202 if it was queued because of a rate limit.
 */

declare(strict_types = 1);

use PHPMailer\PHPMailer\PHPMailer;
use Programster\Http\HttpCode;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface;


class ApiSendController extends AbstractController
{
    private const int MAX_RECIPIENTS = 1000;


    public static function registerRoutes(RouteCollectorProxyInterface $group) : void
    {
        $group->post('/api/v1/send', [self::class, 'send'])->setName('api.send');
    }


    public function send(Request $request, Response $response) : Response
    {
        $clientIp = ClientIpResolver::resolve($request);
        $token = BearerAuth::readToken($request, TokenKind::EMAIL_TOKEN);

        try
        {
            $emailToken = EmailTokenAuthenticator::authenticate($token, $clientIp, MessageSource::API);
        }
        catch (RequestRejectedException $e)
        {
            throw ApiException::createFromRejection($e);
        }

        try
        {
            $email = self::readEmail(self::getJsonBody($request));
        }
        catch (InvalidInputException $e)
        {
            try
            {
                $emailToken->reject(RejectionReason::INVALID_MESSAGE, $e->getMessage());
            }
            catch (RequestRejectedException $rejection)
            {
                throw ApiException::createFromRejection($rejection);
            }
        }

        try
        {
            PolicyEnforcer::checkAddress($emailToken, AddressRuleField::SENDER, $email->getSender());

            foreach ($email->getRecipients() as $recipient)
            {
                PolicyEnforcer::checkAddress($emailToken, AddressRuleField::RECIPIENT, $recipient);
            }

            PolicyEnforcer::checkHeaders($emailToken, $email->getRawEmail());
            $recipients = $email->getRecipients();
            $result = MessageDispatcher::submit($emailToken, $email->getSender(), $recipients, $email->getRawEmail());
        }
        catch (RequestRejectedException $e)
        {
            throw ApiException::createFromRejection($e);
        }

        $status = ($result->getOutcome() === SubmissionOutcome::SENT) ? HttpCode::CREATED : HttpCode::ACCEPTED;
        $output = ['id' => $result->getMessageId(), 'status' => $result->getOutcome()->value];
        return self::json($response, $output, $status);
    }


    /**
     * Build the email from the request body.
     *
     * @param array $body - the decoded JSON body. See the OpenAPI document for its shape.
     *
     * @return ApiEmail
     *
     * @throws InvalidInputException - if anything is missing or invalid.
     */
    private static function readEmail(array $body) : ApiEmail
    {
        $from = self::readContact($body['from'] ?? null, 'from');
        $replyTo = array_key_exists('replyTo', $body) ? self::readContact($body['replyTo'], 'replyTo') : null;
        $subject = $body['subject'] ?? null;
        $text = $body['text'] ?? '';
        $html = $body['html'] ?? '';

        if (!is_string($subject) || trim($subject) === '')
        {
            throw new InvalidInputException('"subject" is required.');
        }

        if (!is_string($text) || !is_string($html) || ($text === '' && $html === ''))
        {
            throw new InvalidInputException('A "text" or "html" body (or both) is required.');
        }

        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->Hostname = Config::get()->getSmtpServerHostname();
        $recipients = [];

        try
        {
            $mailer->setFrom((string)$from['address'], $from['name'], false);

            if ($replyTo !== null)
            {
                $mailer->addReplyTo((string)$replyTo['address'], $replyTo['name']);
            }

            foreach (['to' => 'addAddress', 'cc' => 'addCC', 'bcc' => 'addBCC'] as $field => $method)
            {
                foreach (self::readContactList($body, $field, $field === 'to') as $contact)
                {
                    $mailer->{$method}((string)$contact['address'], $contact['name']);
                    $recipients[] = $contact['address'];
                }
            }

            if (count($recipients) > self::MAX_RECIPIENTS)
            {
                throw new InvalidInputException('An email can have at most ' . self::MAX_RECIPIENTS . ' recipients.');
            }

            $mailer->Subject = $subject;

            if ($html !== '')
            {
                $mailer->isHTML(true);
                $mailer->Body = $html;
                $mailer->AltBody = $text;
            }
            else
            {
                $mailer->Body = $text;
            }

            self::addAttachments($mailer, $body['attachments'] ?? []);
            $mailer->preSend();
        }
        catch (\PHPMailer\PHPMailer\Exception $e)
        {
            throw new InvalidInputException("The email couldn't be built: {$e->getMessage()}");
        }

        $recipientCollection = new EmailAddressCollection(...$recipients);
        return new ApiEmail($from['address'], $recipientCollection, new RawEmail($mailer->getSentMIMEMessage()));
    }


    /**
     * @return array - "address" (an EmailAddress) and "name" (a string, possibly empty).
     *
     * @throws InvalidInputException - if it isn't {"email": "...", "name": "..."} with a valid email.
     */
    private static function readContact(mixed $value, string $field) : array
    {
        if (!is_array($value) || !is_string($value['email'] ?? null) || !is_string($value['name'] ?? ''))
        {
            $msg = "\"{$field}\" must be an object with an \"email\" (and optionally a \"name\").";
            throw new InvalidInputException($msg);
        }

        try
        {
            $address = new EmailAddress($value['email']);
        }
        catch (InvalidInputException $e)
        {
            throw new InvalidInputException("{$field}: {$e->getMessage()}");
        }

        return ['address' => $address, 'name' => trim((string)($value['name'] ?? ''))];
    }


    /**
     * @return array[] - each contact, from readContact().
     *
     * @throws InvalidInputException - if it's invalid, or $isRequired and it's missing or empty.
     */
    private static function readContactList(array $body, string $field, bool $isRequired) : array
    {
        $value = $body[$field] ?? [];

        if (!is_array($value) || !array_is_list($value) || ($isRequired && count($value) === 0))
        {
            $msg = "\"{$field}\" must be an array of contacts" . ($isRequired ? ', with at least one.' : '.');
            throw new InvalidInputException($msg);
        }

        $contacts = [];

        foreach ($value as $index => $item)
        {
            $contacts[] = self::readContact($item, "{$field}[{$index}]");
        }

        return $contacts;
    }


    /**
     * @throws InvalidInputException - if an attachment is invalid.
     *
     * @throws \PHPMailer\PHPMailer\Exception
     */
    private static function addAttachments(PHPMailer $mailer, mixed $attachments) : void
    {
        if (!is_array($attachments) || !array_is_list($attachments))
        {
            throw new InvalidInputException('"attachments" must be an array.');
        }

        foreach ($attachments as $index => $attachment)
        {
            $filename = is_array($attachment) ? ($attachment['filename'] ?? null) : null;
            $content = is_array($attachment) ? base64_decode((string)($attachment['content'] ?? ''), true) : false;
            $contentType = is_array($attachment) ? ($attachment['contentType'] ?? 'application/octet-stream') : '';

            if (!is_string($filename) || trim($filename) === '' || $content === false || !is_string($contentType))
            {
                $msg =
                    "attachments[{$index}] must have a \"filename\", and its \"content\" in base64 (and optionally a"
                    . " \"contentType\").";

                throw new InvalidInputException($msg);
            }

            $mailer->addStringAttachment($content, $filename, PHPMailer::ENCODING_BASE64, $contentType);
        }
    }
}
