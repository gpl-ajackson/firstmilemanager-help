<?php

namespace App\LaunchChecks\Pictures;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The Pictures a change adds or changes: those whose Picture (<name>.png) or Recipe
 * (<name>.yaml) differs from the pull request's base, read from git in the pictures folder.
 * Uncommitted and new files count too, so a writer gets the same answer before committing.
 */
final class ChangedPictures
{
    /**
     * Picture Names, in name order, of the Pictures changed since $base (a branch, tag or
     * commit) that still have a Recipe.
     *
     * @return list<string>
     *
     * @throws RuntimeException when git can't tell (not a repository, or no such ref)
     */
    public static function since(string $picturesPath, string $base): array
    {
        $git = function (array $arguments) use ($picturesPath): string {
            $result = Process::path($picturesPath)->run(['git', ...$arguments]);
            if ($result->failed()) {
                throw new RuntimeException(trim($result->errorOutput()) ?: 'git '.implode(' ', $arguments).' failed');
            }

            return $result->output();
        };

        $from = trim($git(['merge-base', $base, 'HEAD']));
        $files = [
            ...explode("\n", $git(['diff', '--name-only', '--relative', '--no-renames', $from, '--', '.'])),
            ...explode("\n", $git(['ls-files', '--others', '--exclude-standard', '--', '.'])),
        ];

        $names = [];
        foreach ($files as $file) {
            if (preg_match('/\A([^\/]+)\.(?:png|yaml)\z/', trim($file), $match) === 1 && is_file(rtrim($picturesPath, '/')."/{$match[1]}.yaml")) {
                $names[$match[1]] = true;
            }
        }
        $names = array_map(strval(...), array_keys($names));
        sort($names);

        return $names;
    }
}
