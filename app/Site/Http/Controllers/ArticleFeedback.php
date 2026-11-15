<?php

namespace App\Site\Http\Controllers;

use App\Content\Article;
use App\Content\AudienceTier;
use App\Content\ContentLibrary;
use App\Records\Answer;
use App\Records\FeedbackReason;
use App\Records\Records;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * "Did this answer your question?" (spec #30, Article Feedback), posted as a plain form from the
 * end of every Published Article, so it works without script; the site's script only swaps each
 * answer's .feedback part into the Article in place.
 *
 * POST /feedback/<name> answer=yes|no records the vote and says thanks (Yes) or asks what was
 * wrong (No), offering the Article's related articles and a search box. POST
 * /feedback/<name>/reason adds the No's Feedback Reason and comment to that vote. Both run in the
 * public middleware group (no session, so no CSRF token: SameOrigin checks the origin instead)
 * and are never indexed. Votes are rate-limited (routes/public.php).
 */
final class ArticleFeedback
{
    /** Where these votes are given, as a Search Record names the help centre. */
    public const WHERE = 'help centre';

    public function __construct(
        private readonly ContentLibrary $library,
        private readonly Records $records,
    ) {}

    /** How many votes one reader may give one Article in VOTE_MINUTES. */
    public const VOTES_ALLOWED = 3;

    public const VOTE_MINUTES = 10;

    /**
     * The "votes" rate limit (spec #30, Further Note 4): a few votes per reader per Article in ten
     * minutes. Its key is a keyed hash of the reader's IP address and the Article Name, held only
     * by the rate limiter's cache store (config cache.limiter, never the database) and only for
     * those minutes; it is never put in a record or a log. Past the limit the vote is refused
     * with a plain message and nothing is recorded.
     */
    public static function limit(Request $request): Limit
    {
        $name = self::text($request->route('name'));
        $key = hash_hmac('sha256', $request->ip().'|'.$name, config()->string('app.key', ''));

        return Limit::perMinutes(self::VOTE_MINUTES, self::VOTES_ALLOWED)
            ->by($key)
            ->response(function (Request $request, array $headers) use ($name): Response {
                $feedback = app(self::class);

                return $feedback->page($feedback->article($name), 'too-many', status: Response::HTTP_TOO_MANY_REQUESTS)->withHeaders($headers);
            });
    }

    public function vote(Request $request, string $name): Response
    {
        $article = $this->article($name);
        $answer = Answer::tryFrom(ucfirst(self::text($request->input('answer'))));
        if ($answer === null) {
            return $this->page($article, 'unanswered', status: 422);
        }

        $reference = $this->once(
            self::text($request->input('once')), $article, $answer,
            fn (): ?string => $this->records->recordVote($article->name, $article->checked_on, $answer, self::WHERE, $request->userAgent()),
        );

        return $answer === Answer::Yes
            ? $this->page($article, 'thanks')
            : $this->page($article, 'what-was-wrong', ['reference' => $reference]);
    }

    public function reason(Request $request, string $name): Response
    {
        $article = $this->article($name);
        $reference = self::text($request->input('vote'));
        $given = self::text($request->input('reason'));
        $reason = FeedbackReason::tryFrom($given);
        $comment = Records::comment(self::text($request->input('comment')));

        $problem = match (true) {
            $given !== '' && $reason === null => 'Choose one of the reasons below, or none.',
            $comment !== null && mb_strlen($comment) > Records::COMMENT_LIMIT => 'Your comment is '.number_format(mb_strlen($comment)).' characters long. Please shorten it to '.Records::COMMENT_LIMIT.' characters or fewer and send it again.',
            default => null,
        };
        if ($problem !== null) {
            return $this->page($article, 'what-was-wrong', [
                'reference' => $reference,
                'problem' => $problem,
                'chosen' => $reason,
                'comment' => $comment,
            ], status: 422);
        }

        $this->records->giveReason($article->name, $reference, $reason, $comment);

        return $this->page($article, 'told-us');
    }

    /**
     * Records a vote once per click. The site's script marks each click with a random token
     * ("once") and, if the answer is lost on its way back, posts the same form again as plain
     * HTML with the same token; that second post gives back the first one's vote reference
     * instead of recording another vote. The token is held, hashed, only in the rate limiter's
     * store for VOTE_MINUTES (never in a record), and a post without one (no script) is
     * recorded as it is.
     *
     * @param  callable(): ?string  $record
     */
    private function once(string $token, Article $article, Answer $answer, callable $record): ?string
    {
        if (preg_match('/\A[0-9a-f]{32}\z/', $token) !== 1) {
            return $record();
        }

        $store = Cache::store(config()->string('cache.limiter'));
        $key = 'vote-once:'.hash_hmac('sha256', $token.'|'.$article->name.'|'.$answer->value, config()->string('app.key', ''));
        if (! $store->add($key, '', self::VOTE_MINUTES * 60)) {
            $reference = $store->get($key);

            return is_string($reference) && $reference !== '' ? $reference : null;
        }

        $reference = $record();
        $store->put($key, (string) $reference, self::VOTE_MINUTES * 60);

        return $reference;
    }

    /** A form field's text; anything that isn't text (a list, say) is no answer. */
    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** A Published Article; anything else has no feedback (page not found). */
    private function article(string $name): Article
    {
        $article = $this->library->publishedArticle($name);
        abort_if($article === null, 404);

        return $article;
    }

    /**
     * The page after an answer: the Article's name and a way back, then the .feedback part the
     * script swaps into the Article.
     *
     * @param  array<string, mixed>  $data
     */
    private function page(Article $article, string $step, array $data = [], int $status = 200): Response
    {
        return response()
            ->view('site.feedback', [
                'article' => $article,
                'step' => $step,
                'forClients' => $article->audience_tier === AudienceTier::Client,
                'related' => $step === 'what-was-wrong' || $step === 'told-us' ? $this->library->relatedArticles($article) : collect(),
                ...$data,
            ], $status)
            ->header('X-Robots-Tag', 'noindex');
    }
}
