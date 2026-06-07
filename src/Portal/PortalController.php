<?php

declare(strict_types=1);

namespace SearchGateway\Portal;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class PortalController implements RequestHandlerInterface
{
    private const SWAGGER_VERSION = '5.17.14';
    private const SWAGGER_CDN_BASE = 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@' . self::SWAGGER_VERSION;

    private Psr17Factory $factory;

    public function __construct(private readonly OpenApiGenerator $generator)
    {
        $this->factory = new Psr17Factory();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = '/' . ltrim($request->getUri()->getPath(), '/');

        if ($path === '/docs/openapi.json') {
            return $this->json($this->generator->toJson(), 200);
        }
        if ($path === '/docs') {
            return $this->html($this->swaggerHtml(), 200);
        }
        if ($path === '/docs/sandbox') {
            return $this->html($this->sandboxHtml(), 200);
        }
        if ($path === '/docs/portal') {
            return $this->html($this->portalHomeHtml(), 200);
        }

        return $this->json((string) json_encode([
            'ok' => false,
            'error' => 'Unknown portal endpoint: ' . $path,
        ]), 404);
    }

    private function swaggerHtml(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Search Gateway API — Swagger UI</title>
    <link rel="stylesheet" href="__SWAGGER_CDN__/swagger-ui.css">
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="__SWAGGER_CDN__/swagger-ui-bundle.js"></script>
    <script>
        window.onload = function () {
            window.ui = SwaggerUIBundle({
                url: '/docs/openapi.json',
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [SwaggerUIBundle.presets.apis],
            });
        };
    </script>
</body>
</html>
HTML;
    }

    private function sandboxHtml(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Search Gateway — Sandbox</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 960px; margin: 32px auto; padding: 0 16px; color: #1a1a1a; }
        h1 { font-size: 24px; }
        .field { margin: 12px 0; }
        label { display: block; font-weight: 600; margin-bottom: 4px; }
        input, textarea, select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font: inherit; box-sizing: border-box; }
        textarea { font-family: ui-monospace, monospace; min-height: 140px; }
        button { background: #2563eb; color: white; border: 0; padding: 10px 18px; border-radius: 4px; cursor: pointer; }
        pre { background: #0f172a; color: #f1f5f9; padding: 16px; border-radius: 6px; overflow: auto; }
    </style>
</head>
<body>
    <h1>Search Gateway — Sandbox</h1>
    <p>Test any registered route without writing code. Get a key from <code>/admin/keys</code>.</p>
    <div class="field">
        <label for="route">Route</label>
        <select id="route"></select>
    </div>
    <div class="field">
        <label for="apiKey">API Key (sgw_...)</label>
        <input id="apiKey" placeholder="sgw_your_key">
    </div>
    <div class="field">
        <label for="body">JSON Body</label>
        <textarea id="body">{"query":"latest news on PHP frameworks"}</textarea>
    </div>
    <button onclick="send()">Send</button>
    <h2>Response</h2>
    <pre id="result">Click "Send" to see the response…</pre>
    <script>
        async function loadRoutes() {
            const res = await fetch('/docs/openapi.json');
            const spec = await res.json();
            const select = document.getElementById('route');
            for (const [path, methods] of Object.entries(spec.paths || {})) {
                for (const [method, op] of Object.entries(methods)) {
                    const opt = document.createElement('option');
                    opt.value = method.toUpperCase() + ' ' + path;
                    opt.textContent = method.toUpperCase() + ' ' + path + ' — ' + (op.summary || '');
                    select.appendChild(opt);
                }
            }
        }
        async function send() {
            const [method, path] = document.getElementById('route').value.split(' ');
            const apiKey = document.getElementById('apiKey').value;
            const body = document.getElementById('body').value;
            const res = await fetch(path, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + apiKey,
                },
                body: ['POST', 'PUT', 'PATCH'].includes(method) ? body : undefined,
            });
            const text = await res.text();
            try { document.getElementById('result').textContent = JSON.stringify(JSON.parse(text), null, 2); }
            catch { document.getElementById('result').textContent = text; }
        }
        loadRoutes();
    </script>
</body>
</html>
HTML;
    }

    private function portalHomeHtml(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Search Gateway — Developer Portal</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 48px auto; padding: 0 16px; color: #1a1a1a; }
        h1 { font-size: 28px; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin: 16px 0; }
        a { color: #2563eb; text-decoration: none; }
        a:hover { text-decoration: underline; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 3px; font-family: ui-monospace, monospace; }
    </style>
</head>
<body>
    <h1>Search Gateway — Developer Portal</h1>
    <p>Welcome. From here you can explore the API, test endpoints, and manage your keys.</p>
    <div class="card">
        <h2><a href="/docs">API Reference</a></h2>
        <p>Interactive Swagger UI with all registered routes.</p>
    </div>
    <div class="card">
        <h2><a href="/docs/sandbox">Sandbox</a></h2>
        <p>Try any route in your browser without writing code.</p>
    </div>
    <div class="card">
        <h2>Authentication</h2>
        <p>Issue a key with the admin token, then send it as <code>Authorization: Bearer sgw_...</code></p>
    </div>
    <div class="card">
        <h2>OpenAPI Spec</h2>
        <p><a href="/docs/openapi.json">Download JSON</a></p>
    </div>
</body>
</html>
HTML;
    }

    private function json(string $body, int $status): ResponseInterface
    {
        $response = $this->factory->createResponse($status);
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function html(string $body, int $status): ResponseInterface
    {
        $body = str_replace('__SWAGGER_CDN__', self::SWAGGER_CDN_BASE, $body);
        $response = $this->factory->createResponse($status);
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
