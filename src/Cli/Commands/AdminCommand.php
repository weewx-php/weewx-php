<?php

declare(strict_types=1);

namespace WeewxPhp\Cli\Commands;

use WeewxPhp\Admin\Auth;
use WeewxPhp\Admin\ReadModel;
use WeewxPhp\Admin\Service;
use WeewxPhp\Cli\Application;
use WeewxPhp\Cli\Command;
use WeewxPhp\Db\Json;

/** CLI entry to the same typed commands used by the administration site. */
final class AdminCommand implements Command
{
    public function name(): string
    {
        return 'admin';
    }
    public function usage(): string
    {
        return 'admin password-stdin | revision | apply <action> <json-file>';
    }
    public function summary(): string
    {
        return 'provision admin access or apply an admin command';
    }

    public function run(Application $app, array $args): int
    {
        $action = $args[0] ?? '';
        if ($action === 'revision' && count($args) === 1) {
            $app->console()->line((new ReadModel($app->configPath()))->revision);
            return 0;
        }
        if ($action === 'password-stdin' && count($args) === 1) {
            $runtime = $app->runtime();
            $runtime->ensureDataDir();
            $password = fgets(STDIN, 256);
            if ($password === false) {
                throw new \RuntimeException('Read the password from standard input');
            }
            $auth = new Auth($runtime->config->settings);
            try {
                $auth->setPassword(rtrim($password, "\r\n"), $runtime->clock->now());
            } finally {
                $auth->close();
            }
            $app->console()->line('Admin password saved.');
            return 0;
        }
        if ($action === 'apply' && count($args) === 3) {
            $text = file_get_contents($args[2]);
            if ($text === false || strlen($text) > 262144) {
                throw new \RuntimeException('Cannot read command JSON');
            }
            $runtime = $app->runtime();
            $runtime->ensureDataDir();
            (new Service($app->configPath(), $runtime->clock->now()))->execute($args[1], Json::object($text));
            $app->console()->line('Saved.');
            return 0;
        }
        $app->console()->error('usage: ' . $this->usage());
        return Application::USAGE_ERROR;
    }
}
