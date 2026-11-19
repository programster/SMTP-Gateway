<?php

/*
 * The emails that have come through, and the requests that were rejected. An email whose content is kept can be
 * read in the browser (its HTML and plain text versions), or downloaded as an .eml file.
 */

declare(strict_types = 1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface;


class MessagesController extends AbstractController
{
    // An email's HTML is shown in a sandboxed frame, with no scripts, forms or plugins, and nothing loaded from
    // anywhere: not even its remote images, which would tell the sender (or the provider's open tracking) that it was
    // looked at. Its links open in a new tab.
    private const string EMAIL_HTML_CSP =
        "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:; frame-ancestors 'self';"
        . " form-action 'none'; sandbox allow-popups allow-popups-to-escape-sandbox";

    public static function registerRoutes(RouteCollectorProxyInterface $group) : void
    {
        $group->get('/messages', [self::class, 'list'])->setName('messages');
        $group->get('/messages/{id}', [self::class, 'show'])->setName('messages.show');
        $group->get('/messages/{id}/raw', [self::class, 'download'])->setName('messages.download');
        $group->get('/messages/{id}/html', [self::class, 'showHtml'])->setName('messages.html');
        $group->get('/rejections', [self::class, 'listRejections'])->setName('rejections');
    }


    public function list(Request $request, Response $response) : Response
    {
        // Each part of the filter that isn't valid (e.g. from an old link) is left out, rather than refused.
        $emailTokenId = self::getQueryParam($request, 'email_token');
        $status = MessageStatus::tryFrom(self::getQueryParam($request, 'status'));
        $event = EmailEventType::tryFrom(self::getQueryParam($request, 'event'));
        $window = TimeWindow::tryFrom(self::getQueryParam($request, 'within')) ?? TimeWindow::ANY_TIME;
        $filter = MessageFilter::createForEverything()->withWindow($window);
        $filter = TokenKind::EMAIL_TOKEN->isId($emailTokenId) ? $filter->withEmailToken($emailTokenId) : $filter;
        $filter = ($status === null) ? $filter : $filter->withStatuses($status);
        $filter = ($event === null) ? $filter : $filter->withEvents($event);
        $progress = MessageProgress::tryFrom(self::getQueryParam($request, 'progress')) ?? MessageProgress::ANY;
        $filter = $filter->withProgress($progress);
        $page = new ViewMessagesPage(Stats::loadMessages($filter, 200), EmailTokenRepository::loadAll(), $filter);
        return self::renderPage($response, 'Emails', $page);
    }


    public function show(Request $request, Response $response, array $args) : Response
    {
        $message = Stats::loadMessage($args['id']);

        $emailTokenName = $message->isFromService()
            ? ''
            : EmailTokenRepository::load((string)$message->getEmailTokenId())->getName();

        $events = Stats::loadEvents($message);

        try
        {
            $content = EmailContent::createFromRaw(self::loadRawEmail($message));
        }
        catch (NotFoundException)
        {
            // The page says why it isn't available.
            $content = EmailContent::createEmpty();
        }

        $page = new ViewMessagePage($message, $emailTokenName, $events, Stats::loadAttempts($message), $content);
        return self::renderPage($response, 'Email', $page);
    }


    public function download(Request $request, Response $response, array $args) : Response
    {
        return self::downloadEmail($response, Stats::loadMessage($args['id']));
    }


    /**
     * An email's HTML, as a page of its own, for the frame on the email's page. It's sandboxed (see EMAIL_HTML_CSP),
     * so nothing in it can run, or reach this site or anywhere else.
     */
    public function showHtml(Request $request, Response $response, array $args) : Response
    {
        $html = EmailContent::createFromRaw(self::loadRawEmail(Stats::loadMessage($args['id'])))->getHtml();

        // Open its links in a new tab, rather than inside the frame. It goes inside <head> if there is one, so a
        // doctype before it still counts.
        $base = '<base target="_blank">';
        $withBase = (string)preg_replace('/<head(\s[^>]*)?>/i', '$0' . $base, $html, 1, $count);
        $response->getBody()->write(($count === 1) ? $withBase : $base . $html);

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Content-Security-Policy', self::EMAIL_HTML_CSP)
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }


    public function listRejections(Request $request, Response $response) : Response
    {
        $emailTokenId = self::getQueryParam($request, 'email_token');
        $emailTokenId = TokenKind::EMAIL_TOKEN->isId($emailTokenId) ? $emailTokenId : '';
        $window = TimeWindow::tryFrom(self::getQueryParam($request, 'within')) ?? TimeWindow::ANY_TIME;
        $origin = RejectionOrigin::tryFrom(self::getQueryParam($request, 'by')) ?? RejectionOrigin::ANY;
        $rejections = Stats::loadRejections(200, $emailTokenId, $window, $origin);
        $tokens = EmailTokenRepository::loadAll();
        $page = new ViewRejectionsPage($rejections, $tokens, $emailTokenId, $window, $origin);
        return self::renderPage($response, 'Rejected requests', $page);
    }
}
