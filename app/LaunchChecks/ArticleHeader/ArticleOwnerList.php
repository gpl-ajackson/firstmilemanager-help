<?php

namespace App\LaunchChecks\ArticleHeader;

use App\LaunchChecks\Findings;
use App\LaunchChecks\GitHubAccounts;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The Article Owners list, `article-owners.yaml` in the content folder: the GitHub login of
 * each Stockist person who may own an Article. Adding someone is a reviewed change, which is
 * what makes an owner a named person rather than an agent; check 1 also fails a listed login
 * that looks like an agent or bot, and, when asked, one GitHub says isn't a person's account.
 *
 *     - gpl-ajackson
 */
final readonly class ArticleOwnerList
{
    public const FILE = 'article-owners.yaml';

    /** Logins that are agents or bots: GitHub's "[bot]" accounts, "bot" as a word, known agents. */
    private const AGENT = '/(\[bot\]$|(^|-)bot(-|$)|^(claude|copilot|codex|devin|junie|cursor|dependabot|renovate|github-actions)(-|$))/i';

    /**
     * @param  array<string, int>  $logins  login => its line
     */
    private function __construct(public bool $exists, public array $logins) {}

    public static function read(string $contentPath, Findings $findings): self
    {
        $path = $contentPath.'/'.self::FILE;
        if (! is_file($path)) {
            return new self(false, []);
        }

        $contents = (string) file_get_contents($path);
        try {
            $list = Yaml::parse($contents);
        } catch (ParseException $e) {
            $findings->fail('owners.format', self::FILE, $e->getParsedLine() > 0 ? $e->getParsedLine() : 1, 'is not valid YAML: '.$e->getMessage());

            return new self(true, []);
        }

        $lines = preg_split('/\R/', $contents) ?: [];
        $logins = [];
        foreach (is_array($list) ? $list : [null] as $login) {
            if (! is_string($login) || $login === '') {
                $findings->fail('owners.format', self::FILE, 1, 'must be a list of GitHub logins, one "- login" per line');

                continue;
            }
            $logins[$login] = self::lineOf($lines, $login);
        }

        return new self(true, $logins);
    }

    public function has(string $login): bool
    {
        return isset($this->logins[$login]);
    }

    /** Fails listed logins that are agents, and with $github, logins that aren't a person's. */
    public function check(Findings $findings, ?GitHubAccounts $github): void
    {
        foreach ($this->logins as $login => $line) {
            if (preg_match(self::AGENT, $login) === 1) {
                $findings->fail('owners.person', self::FILE, $line, "\"{$login}\" looks like an agent or bot; an Article Owner is a person, never an agent");

                continue;
            }
            if ($github === null) {
                continue;
            }
            $type = $github->accountType($login);
            if ($type === GitHubAccounts::MISSING) {
                $findings->fail('owners.github', self::FILE, $line, "\"{$login}\" is not a GitHub account");
            } elseif ($type === GitHubAccounts::UNKNOWN) {
                $findings->report('owners.github', self::FILE, $line, "\"{$login}\" couldn't be looked up on GitHub this time");
            } elseif ($type !== 'User') {
                $findings->fail('owners.person', self::FILE, $line, "\"{$login}\" is a GitHub {$type} account, not a person's");
            }
        }
    }

    /** @param  list<string>  $lines */
    private static function lineOf(array $lines, string $login): int
    {
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*-\s*([\'"]?)'.preg_quote($login, '/').'\1\s*(#.*)?$/', $line) === 1) {
                return $index + 1;
            }
        }

        return 1;
    }
}
