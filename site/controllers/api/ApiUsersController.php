<?php

/*
 * /api/v1/users: the accounts that can log in to the web interface. Listing and reading them, blocking and unblocking
 * them, archiving them, and deleting archived ones. Needs an API key, which is the authority for these, so no password
 * is asked for. The same rules apply as in the web interface: the last active account can't be blocked or archived,
 * and only an archived account can be deleted (409 Conflict otherwise). Restoring an archived account is done in the
 * web interface.
 */

declare(strict_types = 1);

use Programster\Http\HttpCode;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface;


class ApiUsersController extends AbstractController
{
    public static function registerRoutes(RouteCollectorProxyInterface $group) : void
    {
        $group->get('/users', [self::class, 'list'])->setName('api.users.list');
        $group->get('/users/{id}', [self::class, 'get'])->setName('api.users.get');
        $group->post('/users/{id}/block', [self::class, 'block'])->setName('api.users.block');
        $group->post('/users/{id}/unblock', [self::class, 'unblock'])->setName('api.users.unblock');
        $group->post('/users/{id}/archive', [self::class, 'archive'])->setName('api.users.archive');
        $group->delete('/users/{id}', [self::class, 'delete'])->setName('api.users.delete');
    }


    public function list(Request $request, Response $response) : Response
    {
        $users = [];

        foreach (AdminUserRepository::loadLive() as $user)
        {
            $users[] = ApiPresenter::presentUser($user);
        }

        if (self::getQueryParam($request, 'archived') === 'true')
        {
            foreach (AdminUserRepository::loadArchived() as $user)
            {
                $users[] = ApiPresenter::presentUser($user);
            }
        }

        return self::json($response, ['users' => $users]);
    }


    /**
     * @throws NotFoundException - if there's no such account.
     */
    public function get(Request $request, Response $response, array $args) : Response
    {
        $user = AdminUserRepository::load((string)$args['id']);
        return self::json($response, ['user' => ApiPresenter::presentUser($user)]);
    }


    /**
     * @throws NotFoundException - if there's no such account.
     * @throws AccountStateException - if it's archived, or the last active account (a 409).
     */
    public function block(Request $request, Response $response, array $args) : Response
    {
        $user = AdminUserRepository::block(AdminUserRepository::load((string)$args['id']));
        self::logChange($request, 'Account blocked', $user);
        return self::json($response, ['user' => ApiPresenter::presentUser($user)]);
    }


    /**
     * @throws NotFoundException - if there's no such account.
     * @throws AccountStateException - if it's archived (a 409).
     */
    public function unblock(Request $request, Response $response, array $args) : Response
    {
        $user = AdminUserRepository::unblock(AdminUserRepository::load((string)$args['id']));
        self::logChange($request, 'Account unblocked', $user);
        return self::json($response, ['user' => ApiPresenter::presentUser($user)]);
    }


    /**
     * @throws NotFoundException - if there's no such account.
     * @throws AccountStateException - if it's already archived, or the last active account (a 409).
     */
    public function archive(Request $request, Response $response, array $args) : Response
    {
        $user = AdminUserRepository::archive(AdminUserRepository::load((string)$args['id']));
        self::logChange($request, 'Account archived', $user);
        return self::json($response, ['user' => ApiPresenter::presentUser($user)]);
    }


    /**
     * @throws NotFoundException - if there's no such account.
     * @throws AccountStateException - if it isn't archived (a 409, ACCOUNT_NOT_ARCHIVED).
     */
    public function delete(Request $request, Response $response, array $args) : Response
    {
        $user = AdminUserRepository::load((string)$args['id']);
        AdminUserRepository::delete($user);
        self::logChange($request, 'Account deleted', $user);
        return $response->withStatus(HttpCode::NO_CONTENT->value);
    }


    private static function logChange(Request $request, string $message, AdminUser $user) : void
    {
        $apiKey = $request->getAttribute('api_key');

        ServiceFactory::getLogger(LogCategory::ADMIN)->notice($message, [
            'user_id' => $user->getId(),
            'by_api_key' => ($apiKey instanceof ApiKey) ? $apiKey->getId() : '',
        ]);
    }
}
