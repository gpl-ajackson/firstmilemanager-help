<?php

namespace App\Site;

/**
 * Whether the help centre has gone public (config help.going_public, the one switch). Until it
 * has, every page and robots.txt say "noindex", the sitemap is empty and nothing is recorded
 * (App\Records\Records::isRecording()). Nothing else reads the setting.
 */
final class GoingPublic
{
    public static function isOn(): bool
    {
        return config('help.going_public') === true;
    }

    /**
     * Runs $callback as if the help centre had gone public, then puts the setting back: for
     * Launch Check 9, which reads the sitemap and pages as search engines will once it has.
     * Only ever within one run of the checks; the help centre itself goes public by the reviewed
     * change to config/help.php.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function asIfOn(callable $callback): mixed
    {
        $was = config('help.going_public');
        config(['help.going_public' => true]);
        try {
            return $callback();
        } finally {
            config(['help.going_public' => $was]);
        }
    }
}
