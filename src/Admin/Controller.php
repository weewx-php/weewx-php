<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use Throwable;

/** No globals: transport checks, authentication and commands can be exercised together. */
final class Controller
{
    public function __construct(private readonly string $path, private readonly int $now, private readonly bool $localHttp = false) {}

    /** @param array<string, mixed> $query
     * @param array<string, mixed> $post */
    public function handle(string $method, array $query, array $post, ?string $cookie, string $peer, bool $https): Response
    {
        if (!$https && !($this->localHttp && in_array($peer, ['127.0.0.1', '::1'], true))) {
            return new Response(403, 'HTTPS required');
        }
        if (!in_array($method, ['GET', 'POST'], true)) {
            return new Response(405, 'Method not allowed');
        }
        $auth = null;
        try {
            $read = new ReadModel($this->path);
            $language = $read->file->root()->optionalSection('Admin')?->optional('language')?->string() ?? 'en';
            $translator = new Translator(Input::text($query, 'lang', $language));
            $auth = new Auth($read->config->settings);
            $session = $auth->session($cookie, $this->now);
            $page = Input::text($query, 'page', 'overview');
            if (!in_array($page, Page::PAGES, true)) {
                return new Response(404, $translator->text('error.not_found'), $session['token']);
            }
            $error = '';
            $detail = '';
            $status = 200;
            if ($method === 'POST') {
                try {
                    Auth::csrf($session['csrf'], Input::text($post, 'csrf'));
                    $action = Input::text($post, 'action');
                    if ($action === 'login') {
                        $session = $auth->login($session['token'], Input::text($post, 'csrf'), Input::text($post, 'password'), $peer, $this->now);
                        return new Response(303, token: $session['token'], location: '?page=overview');
                    }
                    if (!$session['authenticated']) {
                        throw new Problem('error.login_required', status: 403);
                    }
                    if ($action === 'mapping.save_all' && Input::text($post, 'complete') !== '1') {
                        throw new Problem('error.incomplete_form');
                    }
                    if ($action === 'logout') {
                        $auth->logout($session['token'], $this->now);
                        return new Response(303, token: '', location: '?page=overview');
                    }
                    if ($action === 'station.connection') {
                        $store = \WeewxPhp\Ingest\Store::open($read->config->settings);
                        try {
                            $keys = $store->credentials();
                        } finally {
                            $store->close();
                        }
                        $auth->audit('connection.reveal', $this->now);
                        return new Response(200, (new Page($read, $translator, $session['csrf']))->connection($keys), $session['token']);
                    }
                    (new Service($this->path, $this->now))->execute($action, $post);
                    $destination = ['page' => $page, 'saved' => '1'];
                    foreach (['archive', 'station', 'theme', 'upload'] as $key) {
                        $value = Input::text($post, $key);
                        if ($value !== '') {
                            $destination[$key] = $value;
                        }
                    }
                    return new Response(303, token: $session['token'], location: '?' . http_build_query($destination));
                } catch (Problem $problem) {
                    $error = $problem->getMessage();
                    $status = $problem->status;
                    $auth->audit('request.rejected', $this->now);
                } catch (\InvalidArgumentException|\WeewxPhp\Config\ConfigError|\WeewxPhp\Archive\MappingError $problem) {
                    $error = 'error.validation';
                    // Configuration serializer errors can contain credential values.
                    $detail = $problem instanceof \WeewxPhp\Archive\MappingError ? $problem->getMessage() : '';
                    $status = 422;
                    $auth->audit('validation.failed', $this->now);
                }
            }
            $view = new Page($read, $translator, $session['csrf'], $post);
            if (!$session['authenticated']) {
                if (Input::text($query, 'format') === 'column') {
                    return new Response(403, '{}', $session['token'], contentType: 'application/json; charset=utf-8');
                }
                return new Response($status, $view->login($auth->configured(), $error), $session['token']);
            }
            $archive = Input::text($query, 'archive');
            if (in_array($page, ['archives', 'fields'], true) && $archive !== '' && $read->config->archive($archive) === null) {
                return new Response(404, $translator->text('error.not_found'), $session['token']);
            }
            if ($method === 'GET' && $page === 'fields' && Input::text($query, 'format') === 'column') {
                $configuration = $read->config->archive($archive) ?? throw new Problem('error.archive');
                $column = Input::text($query, 'column');
                $stats = Inventory::read($configuration, [$column])['columns'][$column];
                return new Response(200, json_encode($view->historyData($configuration, $column, $stats), JSON_THROW_ON_ERROR), $session['token'], contentType: 'application/json; charset=utf-8');
            }
            return new Response($status, $view->render($page, $query, $error, $detail), $session['token']);
        } catch (Throwable $error) {
            error_log('weewx-php admin: ' . $error::class);
            return new Response(503, (new Translator())->text('error.unavailable'));
        } finally {
            $auth?->close();
        }
    }
}
