<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use WeewxPhp\Db\Json;

/** Authenticated same-origin import requests; the body is read only after session and CSRF checks. */
final class ImportController
{
    public function __construct(private readonly string $path, private readonly int $now, private readonly string $webspace, private readonly bool $localHttp = false) {}

    /** @param array<string, mixed> $query
     * @param callable(int): string $body
     */
    public function handle(string $method, array $query, ?string $cookie, string $csrf, string $peer, bool $https, int $length, callable $body): Response
    {
        $auth = null;
        $token = null;
        $language = new Translator('en');
        try {
            $language = new Translator(Input::text($query, 'lang', 'en'));
            if (!$https && !($this->localHttp && in_array($peer, ['127.0.0.1', '::1'], true))) {
                throw new Problem('error.login_required', status: 403);
            }
            if ($method !== 'POST') {
                throw new Problem('error.input', status: 405);
            }
            $read = new ReadModel($this->path);
            $auth = new Auth($read->config->settings);
            $session = $auth->session($cookie, $this->now);
            $token = $session['token'];
            if (!$session['authenticated']) {
                throw new Problem('error.login_required', status: 403);
            }
            Auth::csrf($session['csrf'], $csrf);
            // Chunking bounds transfers and repair steps; host/FPM timeouts still apply.
            if (function_exists('set_time_limit')) {
                set_time_limit(0);
            }
            $action = Input::text($query, 'action');
            $limit = $action === 'chunk' ? ImportFiles::CHUNK : 65536;
            if ($length < 0 || $length > $limit) {
                throw new Problem('error.import_size', status: 413);
            }
            $text = $body($limit + 1);
            if (strlen($text) > $limit) {
                throw new Problem('error.import_size', status: 413);
            }
            $input = $action === 'chunk' ? $query : Json::object($text);
            $id = Input::text($input, 'id');
            $import = new ArchiveImport($this->path, $this->now, $this->webspace);
            $result = match ($action) {
                'begin' => $import->files->upload(Input::text($input, 'name'), ImportFiles::integer($input['size'] ?? null)),
                'chunk' => $import->files->chunk($id, $this->offset(Input::text($query, 'offset')), $text),
                'status' => $import->publicState($id, $import->files->read($id)),
                'inspect' => $import->inspect($id),
                'search' => $import->files->search($id === '' ? null : $id),
                'select' => $import->files->select(Input::text($input, 'search'), Input::text($input, 'key')),
                'detect' => $import->detect($id),
                'commit' => $import->commit($id, Input::text($input, 'name'), Input::text($input, 'timezone')),
                'step' => $import->step($id),
                'discard' => $import->files->discard($id),
                default => throw new Problem('error.input'),
            };
            if (in_array($action, ['begin', 'select', 'commit', 'discard'], true)) {
                $auth->audit('archive.import.' . $action, $this->now);
            }
            return new Response(200, json_encode($result, JSON_THROW_ON_ERROR), $token, contentType: 'application/json; charset=utf-8');
        } catch (Problem $error) {
            return new Response($error->status, json_encode(['error' => $language->text($error->getMessage())], JSON_THROW_ON_ERROR), $token, contentType: 'application/json; charset=utf-8');
        } catch (\Throwable $error) {
            error_log('weewx-php import: ' . $error::class);
            return new Response(422, json_encode(['error' => $language->text('error.import_failed')], JSON_THROW_ON_ERROR), $token, contentType: 'application/json; charset=utf-8');
        } finally {
            $auth?->close();
        }
    }

    private function offset(string $text): int
    {
        if (preg_match('/^\d{1,11}$/D', $text) !== 1) {
            throw new Problem('error.input');
        }
        return (int) $text;
    }
}
