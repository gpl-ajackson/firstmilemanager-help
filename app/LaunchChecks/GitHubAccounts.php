<?php

namespace App\LaunchChecks;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Looks GitHub logins up through GitHub's REST API (GET /users/{login}), so check 1 can tell a
 * person's account from a bot or an organisation. Read-only and public; a token, when CI has
 * one, only raises the rate limit. Tests fake it with Http::fake().
 */
final readonly class GitHubAccounts
{
    public const MISSING = 'missing';

    public const UNKNOWN = 'unknown';

    public function __construct(private ?string $token = null) {}

    /** GitHub's account type ("User", "Bot", "Organization"), MISSING, or UNKNOWN on an error. */
    public function accountType(string $login): string
    {
        $request = Http::acceptJson()->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])->timeout(10);
        if ($this->token !== null && $this->token !== '') {
            $request = $request->withToken($this->token);
        }

        try {
            $response = $request->get('https://api.github.com/users/'.rawurlencode($login));
        } catch (ConnectionException) {
            return self::UNKNOWN;
        }

        if ($response->status() === 404) {
            return self::MISSING;
        }
        $type = $response->successful() ? $response->json('type') : null;

        return is_string($type) ? $type : self::UNKNOWN;
    }
}
