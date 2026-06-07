<?php

declare(strict_types=1);

namespace SearchGateway\Controller;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SearchGateway\Admin\AdminAuth;
use SearchGateway\Admin\AnalyticsAdminService;
use SearchGateway\Admin\HealthAdminService;
use SearchGateway\Admin\KeyAdminService;
use SearchGateway\Admin\RouteAdminService;
use SearchGateway\Contract\SearchGatewayException;
use SearchGateway\Http\JsonResponse;

final class AdminController implements RequestHandlerInterface
{
    private Psr17Factory $factory;

    public function __construct(
        private readonly AdminAuth $auth,
        private readonly RouteAdminService $routes,
        private readonly KeyAdminService $keys,
        private readonly HealthAdminService $health,
        private readonly AnalyticsAdminService $analytics,
    ) {
        $this->factory = new Psr17Factory();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->auth->isEnabled()) {
            return JsonResponse::create($this->response(), 503, [
                'ok' => false,
                'error' => 'Admin API disabled (set SGW_ADMIN_TOKEN env var)',
            ]);
        }

        $token = $this->auth->extractBearer($request->getHeaderLine('Authorization'));
        if ($token === null) {
            return $this->unauthorized('Missing admin Authorization: Bearer header');
        }

        $path = '/' . ltrim($request->getUri()->getPath(), '/');
        $method = strtoupper($request->getMethod());

        try {
            return $this->dispatch($method, $path, $request, $token);
        } catch (SearchGatewayException $e) {
            $status = $e->getCode() === 0 ? 400 : $e->getCode();
            return JsonResponse::create($this->response(), $status, [
                'ok' => false,
                'error' => $e->getMessage(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::create($this->response(), 400, [
                'ok' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function dispatch(string $method, string $path, ServerRequestInterface $request, string $token): ResponseInterface
    {
        if ($path === '/admin/health' && $method === 'GET') {
            $this->auth->authenticate($token, [AdminAuth::ROLE_READ]);
            return JsonResponse::create($this->response(), 200, [
                'ok' => true,
                'data' => $this->health->report(),
            ]);
        }

        if ($path === '/admin/analytics' && $method === 'GET') {
            $this->auth->authenticate($token, [AdminAuth::ROLE_READ]);
            return JsonResponse::create($this->response(), 200, [
                'ok' => true,
                'data' => $this->analytics->summary(),
            ]);
        }

        if ($path === '/admin/routes') {
            return $this->handleRoutesCollection($method, $request, $token);
        }

        if (preg_match('#^/admin/routes/([^/]+)$#', $path, $m) === 1) {
            return $this->handleRoutesItem($method, $m[1], $token);
        }

        if ($path === '/admin/keys') {
            return $this->handleKeysCollection($method, $request, $token);
        }

        if (preg_match('#^/admin/keys/([^/]+)$#', $path, $m) === 1) {
            return $this->handleKeysItem($method, $m[1], $token);
        }

        return JsonResponse::create($this->response(), 404, [
            'ok' => false,
            'error' => sprintf('Unknown admin endpoint: %s %s', $method, $path),
        ]);
    }

    private function handleRoutesCollection(string $method, ServerRequestInterface $request, string $token): ResponseInterface
    {
        if ($method === 'GET') {
            $this->auth->authenticate($token, [AdminAuth::ROLE_READ]);
            return JsonResponse::create($this->response(), 200, [
                'ok' => true,
                'data' => [
                    'count' => $this->routes->count(),
                    'routes' => $this->routes->list(),
                ],
            ]);
        }
        if ($method === 'POST') {
            $this->auth->authenticate($token, [AdminAuth::ROLE_ROUTES]);
            $body = $this->jsonBody($request);
            return JsonResponse::create($this->response(), 201, [
                'ok' => true,
                'data' => $this->routes->register($body),
            ]);
        }
        return $this->methodNotAllowed(['GET', 'POST']);
    }

    private function handleRoutesItem(string $method, string $name, string $token): ResponseInterface
    {
        if ($method === 'GET') {
            $this->auth->authenticate($token, [AdminAuth::ROLE_READ]);
            $row = $this->routes->get($name);
            if ($row === null) {
                return $this->notFound('Route not found: ' . $name);
            }
            return JsonResponse::create($this->response(), 200, [
                'ok' => true,
                'data' => $row,
            ]);
        }
        if ($method === 'DELETE') {
            $this->auth->authenticate($token, [AdminAuth::ROLE_ROUTES]);
            if (!$this->routes->remove($name)) {
                return $this->notFound('Route not found: ' . $name);
            }
            return JsonResponse::create($this->response(), 200, [
                'ok' => true,
                'data' => ['removed' => $name],
            ]);
        }
        return $this->methodNotAllowed(['GET', 'DELETE']);
    }

    private function handleKeysCollection(string $method, ServerRequestInterface $request, string $token): ResponseInterface
    {
        if ($method === 'GET') {
            $this->auth->authenticate($token, [AdminAuth::ROLE_READ]);
            return JsonResponse::create($this->response(), 200, [
                'ok' => true,
                'data' => [
                    'count' => $this->keys->count(),
                    'keys' => $this->keys->list(),
                ],
            ]);
        }
        if ($method === 'POST') {
            $this->auth->authenticate($token, [AdminAuth::ROLE_KEYS]);
            $body = $this->jsonBody($request);
            return JsonResponse::create($this->response(), 201, [
                'ok' => true,
                'data' => $this->keys->create($body),
            ]);
        }
        return $this->methodNotAllowed(['GET', 'POST']);
    }

    private function handleKeysItem(string $method, string $id, string $token): ResponseInterface
    {
        if ($method === 'GET') {
            $this->auth->authenticate($token, [AdminAuth::ROLE_READ]);
            $row = $this->keys->find($id);
            if ($row === null) {
                return $this->notFound('Key not found: ' . $id);
            }
            return JsonResponse::create($this->response(), 200, [
                'ok' => true,
                'data' => $row,
            ]);
        }
        if ($method === 'DELETE') {
            $this->auth->authenticate($token, [AdminAuth::ROLE_KEYS]);
            $revoked = $this->keys->revoke($id);
            if (!$revoked) {
                return $this->notFound('Key not found or already revoked: ' . $id);
            }
            return JsonResponse::create($this->response(), 200, [
                'ok' => true,
                'data' => ['revoked' => $id],
            ]);
        }
        return $this->methodNotAllowed(['GET', 'DELETE']);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }
        $raw = (string) $request->getBody();
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Invalid JSON body: ' . $e->getMessage());
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function unauthorized(string $message): ResponseInterface
    {
        return JsonResponse::create($this->response(), 401, [
            'ok' => false,
            'error' => $message,
        ]);
    }

    private function notFound(string $message): ResponseInterface
    {
        return JsonResponse::create($this->response(), 404, [
            'ok' => false,
            'error' => $message,
        ]);
    }

    /**
     * @param list<string> $allowed
     */
    private function methodNotAllowed(array $allowed): ResponseInterface
    {
        return JsonResponse::create($this->response(), 405, [
            'ok' => false,
            'error' => 'Method not allowed; allowed: ' . implode(', ', $allowed),
        ]);
    }

    private function response(): ResponseInterface
    {
        return $this->factory->createResponse();
    }
}
