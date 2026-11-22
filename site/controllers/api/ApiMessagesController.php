<?php

/*
 * /api/v1/messages: the emails that have come through, and what happened to them. Needs a management API key.
 */

declare(strict_types = 1);

use Programster\Http\HttpCode;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface;


class ApiMessagesController extends AbstractController
{
    public static function registerRoutes(RouteCollectorProxyInterface $group) : void
    {
        $group->get('/messages', [self::class, 'list'])->setName('api.messages.list');
        $group->get('/messages/{id}', [self::class, 'get'])->setName('api.messages.get');
        $group->get('/messages/{id}/raw', [self::class, 'download'])->setName('api.messages.download');
    }


    public function list(Request $request, Response $response) : Response
    {
        $emailTokenId = self::getQueryParam($request, 'emailTokenId');
        $statusName = self::getQueryParam($request, 'status');
        $limit = self::getQueryParam($request, 'limit');
        $status = MessageStatus::tryFrom($statusName);

        if ($emailTokenId !== '' && TokenKind::EMAIL_TOKEN->isId($emailTokenId) === false)
        {
            $message = '"emailTokenId" must be an email token ID.';
            throw new ApiException(HttpCode::BAD_REQUEST, ErrorCodeEnum::INVALID_INPUT, $message);
        }

        if ($statusName !== '' && $status === null)
        {
            $valid = implode(', ', array_column(MessageStatus::cases(), 'value'));
            $message = "\"status\" must be one of: {$valid}.";
            throw new ApiException(HttpCode::BAD_REQUEST, ErrorCodeEnum::INVALID_INPUT, $message);
        }

        if ($limit !== '' && (ctype_digit($limit) === false || (int)$limit < 1 || (int)$limit > 500))
        {
            $message = '"limit" must be from 1 to 500.';
            throw new ApiException(HttpCode::BAD_REQUEST, ErrorCodeEnum::INVALID_INPUT, $message);
        }

        $filter = MessageFilter::createForEverything();
        $filter = ($emailTokenId === '') ? $filter : $filter->withEmailToken($emailTokenId);
        $filter = ($status === null) ? $filter : $filter->withStatuses($status);
        $messages = [];
        $count = ($limit === '') ? 100 : (int)$limit;

        foreach (Stats::loadMessages($filter, $count) as $message)
        {
            $messages[] = ApiPresenter::presentMessage($message);
        }

        return self::json($response, ['messages' => $messages]);
    }


    /**
     * Download an email's content, if it's kept (see the retention settings).
     */
    public function download(Request $request, Response $response, array $args) : Response
    {
        return self::downloadEmail($response, Stats::loadMessage($args['id']));
    }


    public function get(Request $request, Response $response, array $args) : Response
    {
        $message = Stats::loadMessage($args['id']);
        $events = [];
        $attempts = [];

        foreach (Stats::loadEvents($message) as $event)
        {
            $events[] = ApiPresenter::presentEvent($event);
        }

        foreach (Stats::loadAttempts($message) as $attempt)
        {
            $attempts[] = ApiPresenter::presentAttempt($attempt);
        }

        $body = [
            'message' => ApiPresenter::presentMessage($message),
            'deliveryAttempts' => $attempts,
            'events' => $events,
        ];

        return self::json($response, $body);
    }
}
