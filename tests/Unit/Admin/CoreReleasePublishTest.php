<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeewxPhp\Tests\Support\TempDir;

final class CoreReleasePublishTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function scenarios(): iterable
    {
        yield 'stable' => ['v1.0.0', 'ok', true];
        yield 'beta' => ['v1.1.0-beta.2', 'ok', true];
        yield 'corrupt artifact' => ['v1.0.0', 'corrupt', false];
        yield 'tag moved before draft' => ['v1.0.0', 'moved-before', false];
        yield 'tag moved after upload' => ['v1.0.0', 'moved-after', false];
        yield 'upload or existing release failure' => ['v1.0.0', 'upload-fails', false];
        yield 'missing digest' => ['v1.0.0', 'missing-digest', false];
        yield 'different asset' => ['v1.0.0', 'wrong-digest', false];
        yield 'truncated asset' => ['v1.0.0', 'wrong-size', false];
        yield 'draft already published' => ['v1.0.0', 'not-draft', false];
        yield 'missing installation archive' => ['v1.0.0', 'missing-zip', false];
    }

    #[DataProvider('scenarios')]
    public function testOnlyVerifiedAssetsArePublished(string $tag, string $scenario, bool $success): void
    {
        $dir = TempDir::create('publish');
        try {
            mkdir($dir . '/dist');
            mkdir($dir . '/bin');
            $body = '{"schema":1}';
            $digest = hash('sha256', $body);
            file_put_contents($dir . '/dist/weewx-php-core.json', $scenario === 'corrupt' ? $body . ' ' : $body);
            file_put_contents($dir . '/dist/SHA256SUMS', $digest . "  weewx-php-core.json\n");
            file_put_contents($dir . '/dist/weewx-php-core.zip', $body);
            file_put_contents($dir . '/dist/SHA256SUMS', $digest . "  weewx-php-core.zip\n", FILE_APPEND);
            // Simulate GitHub failures while executing the actual publishing script offline.
            file_put_contents($dir . '/bin/gh', <<<'SH'
                #!/bin/sh
                set -eu
                printf '%s\n' "$*" >> "$CALL_LOG"
                case "$1 $2" in
                    'release create')
                        test "$SCENARIO" != upload-fails
                        touch "$CREATED"
                        ;;
                    'api --method') printf 'published\n' ;;
                    'release view')
                        if [ "$SCENARIO" != not-draft ]; then printf '42\n'; fi
                        ;;
                    api*)
                        case "$2" in
                            */commits/*)
                                if [ "$SCENARIO" = moved-before ] || { [ "$SCENARIO" = moved-after ] && [ -f "$CREATED" ]; }; then
                                    printf 'other-commit\n'
                                else
                                    printf '%s\n' "$RELEASE_COMMIT"
                                fi
                                ;;
                            */releases/42)
                                if [ "$SCENARIO" = missing-zip ]; then
                                    case "$4" in *weewx-php-core.zip*) exit 0 ;; esac
                                fi
                                case "$SCENARIO" in
                                    missing-digest) printf 'null %s\n' "$ASSET_SIZE" ;;
                                    wrong-digest) printf 'sha256:wrong %s\n' "$ASSET_SIZE" ;;
                                    wrong-size) printf 'sha256:%s 1\n' "$ASSET_DIGEST" ;;
                                    *) printf 'sha256:%s %s\n' "$ASSET_DIGEST" "$ASSET_SIZE" ;;
                                esac
                                ;;
                            *) exit 2 ;;
                        esac
                        ;;
                    *) exit 2 ;;
                esac
                SH);
            $process = proc_open(['sh', '-c', 'gh() { sh "$GH_STUB" "$@"; }; . "$PUBLISH_SCRIPT"'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, [
                'PATH' => '/usr/bin:/bin',
                'GH_STUB' => $dir . '/bin/gh',
                'PUBLISH_SCRIPT' => dirname(__DIR__, 3) . '/scripts/publish_core_release.sh',
                'GH_REPO' => 'weewx-php/weewx-php',
                'RELEASE_TAG' => $tag,
                'RELEASE_COMMIT' => str_repeat('a', 40),
                'SCENARIO' => $scenario,
                'ASSET_DIGEST' => $digest,
                'ASSET_SIZE' => (string) strlen($body),
                'CALL_LOG' => $dir . '/calls',
                'CREATED' => $dir . '/created',
            ]);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $calls = is_file($dir . '/calls') ? (string) file_get_contents($dir . '/calls') : '';
            if ($success) {
                self::assertSame(0, $exit, (string) $error);
                self::assertStringContainsString('published', (string) $output);
                self::assertStringContainsString('--verify-tag --draft', $calls);
                self::assertStringContainsString('api --method PATCH repos/weewx-php/weewx-php/releases/42 -F draft=false', $calls);
                self::assertStringContainsString(str_contains($tag, '-beta.') ? '-F prerelease=true -f make_latest=false' : '-F prerelease=false -f make_latest=legacy', $calls);
            } else {
                self::assertNotSame(0, $exit);
                self::assertStringNotContainsString('--method PATCH', $calls);
                if (in_array($scenario, ['corrupt', 'moved-before'], true)) {
                    self::assertStringNotContainsString('release create', $calls);
                }
            }
        } finally {
            TempDir::remove($dir);
        }
    }
}
