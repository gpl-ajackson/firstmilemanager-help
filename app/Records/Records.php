<?php

namespace App\Records;

use App\Preview\HelpPreview;
use App\Site\GoingPublic;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records (spec #30): what the help centre keeps about how it is used, on its own server and
 * never about the reader: Page View Counts and Still Stuck Counts, each a page (or an Article
 * Name), a day and a number; Search Records (cleaned words, results shown, outcome, the result
 * opened and its place, a day and where); and Article Feedback votes. Pruning, monthly uptime and
 * the Help Gaps Report's figures come here too. Everything that records goes through this class,
 * so nothing about readers is recorded while isRecording() is false.
 */
final class Records
{
    /** Records are dated by the day in the UK, where the help centre's readers are. */
    public const TIMEZONE = 'Europe/London';

    /** The longest comment a reader may add to a No, in characters. */
    public const COMMENT_LIMIT = 500;

    /** Search Records, votes and Feedback Reasons are kept this many months (rule 11). */
    public const KEEP_RECORDS_MONTHS = 13;

    /** Feedback comments, where personal details slip in, are kept this many months (rule 11). */
    public const KEEP_COMMENTS_MONTHS = 6;

    /**
     * Whether anything is recorded. Nothing is until the help centre goes public, so the
     * baseline is real (user story 158), and nothing ever is on a Help Preview: a preview is a
     * static copy the server never sees, and while one is written nothing is recorded either.
     */
    public function isRecording(): bool
    {
        return GoingPublic::isOn() && ! HelpPreview::isOn();
    }

    /** Today's date in the UK: the only time a record holds. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }

    /**
     * Adds one to today's Page View Count for a page (its path, with no query string). The user
     * agent only decides whether the view was an obvious bot's; it is never kept.
     */
    public function countPageView(string $page, ?string $userAgent): void
    {
        if (! Bots::isObvious($userAgent)) {
            $this->addOne('page_view_counts', 'page', $page, 'views');
        }
    }

    /**
     * Each page's views between two days, both included, busiest first.
     *
     * @return array<string, int> page => views
     */
    public function pageViews(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->totals('page_view_counts', 'page', 'views', $from, $to);
    }

    /**
     * Adds one to today's Still Stuck Count for a staff Article, by its Article Name. As with
     * page views, the user agent only leaves out obvious bots and is never kept.
     */
    public function countStillStuck(string $articleName, ?string $userAgent): void
    {
        if (! Bots::isObvious($userAgent)) {
            $this->addOne('still_stuck_counts', 'article', $articleName, 'clicks');
        }
    }

    /**
     * Each Article's "Still stuck?" clicks between two days, both included, most first.
     *
     * @return array<string, int> Article Name => clicks
     */
    public function stillStuckCounts(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->totals('still_stuck_counts', 'article', 'clicks', $from, $to);
    }

    /**
     * Records one Article Feedback vote, with the Article's Checked On date at the time (so its
     * helpful share counts from its last review), today's UK date and where it was given. Gives
     * back the vote's reference, for the reason and comment a No may add (giveReason()), or null
     * when nothing was recorded: before going public, or for an obvious bot. The user agent is
     * only read, never kept.
     */
    public function recordVote(string $articleName, CarbonInterface $checkedOn, Answer $answer, string $where, ?string $userAgent): ?string
    {
        if (! $this->isRecording() || Bots::isObvious($userAgent)) {
            return null;
        }

        $reference = (string) Str::uuid();
        DB::table('article_feedback')->insert([
            'id' => $reference,
            'article' => $articleName,
            'answer' => $answer->value,
            'checked_on' => $checkedOn->toDateString(),
            'day' => self::today()->toDateString(),
            'where_given' => $where,
        ]);

        return $reference;
    }

    /**
     * Adds a Feedback Reason and comment to the No vote on $articleName with this reference. A
     * vote takes them once, and only on its own day (a past month's votes are already added up,
     * totalUp()): a Yes, a vote that already has either, one given on an earlier day, or a
     * reference that isn't a vote's on that Article is left alone, quietly. The comment is at most COMMENT_LIMIT characters (the
     * caller refuses longer ones, telling the reader) and an empty one is no comment.
     */
    public function giveReason(string $articleName, string $reference, ?FeedbackReason $reason, ?string $comment): void
    {
        $comment = self::comment($comment);
        if (! $this->isRecording() || ! Str::isUuid($reference) || ($reason === null && $comment === null)) {
            return;
        }

        DB::table('article_feedback')
            ->where('id', $reference)
            ->where('article', $articleName)
            ->where('day', self::today()->toDateString())
            ->where('answer', Answer::No->value)
            ->whereNull('reason')
            ->whereNull('comment')
            ->update(['reason' => $reason?->value, 'comment' => $comment]);
    }

    /**
     * A comment as kept: line breaks as one character, no white space around it, null when empty.
     * Its length is what COMMENT_LIMIT limits.
     */
    public static function comment(?string $comment): ?string
    {
        $comment = trim(str_replace(["\r\n", "\r"], "\n", (string) $comment));

        return $comment === '' ? null : $comment;
    }

    /**
     * Every vote given between two days, both included, oldest first. The issue desk (#48) and the
     * Help Gaps Report read votes here.
     *
     * @return list<ArticleVote>
     */
    public function votes(CarbonInterface $from, CarbonInterface $to): array
    {
        return array_values(DB::table('article_feedback')
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->orderBy('day')
            ->orderBy('article')
            ->get()
            ->map(fn (object $row): ArticleVote => new ArticleVote(
                article: (string) $row->article,
                answer: Answer::from((string) $row->answer),
                reason: $row->reason === null ? null : FeedbackReason::from((string) $row->reason),
                comment: $row->comment === null ? null : (string) $row->comment,
                checkedOn: CarbonImmutable::parse((string) $row->checked_on),
                day: CarbonImmutable::parse((string) $row->day),
                where: (string) $row->where_given,
                reference: (string) $row->id,
            ))
            ->all());
    }

    /**
     * The "doesn't match" votes the issue desk hasn't sent to their Article Owners yet: No votes
     * whose Feedback Reason is "It doesn't match what I see", oldest day first. A vote keeps no
     * time, so votes of one day come in no particular order.
     *
     * @return list<DoesntMatchVote>
     */
    public function doesntMatchVotesToSend(): array
    {
        return array_values(DB::table('article_feedback')
            ->where('answer', Answer::No->value)
            ->where('reason', FeedbackReason::DoesntMatch->value)
            ->where('sent_to_issue', false)
            ->orderBy('day')
            ->orderBy('article')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): DoesntMatchVote => new DoesntMatchVote(
                reference: (string) $row->id,
                article: (string) $row->article,
                comment: $row->comment === null ? null : (string) $row->comment,
                checkedOn: CarbonImmutable::parse((string) $row->checked_on),
                day: CarbonImmutable::parse((string) $row->day),
            ))
            ->all());
    }

    /** Marks a "doesn't match" vote as sent to its Article Owner's issue, so it is sent once. */
    public function sentToIssue(string $reference): void
    {
        DB::table('article_feedback')->where('id', $reference)->update(['sent_to_issue' => true]);
    }

    /**
     * Records one settled Search under the id the search service gave it, its words cleaned of
     * anything that looks like a personal detail (Cleaning). Its outcome starts as
     * No results when nothing was shown (not even a Glossary Entry) and as Not opened otherwise,
     * until searchOpened() marks it. Recording the same id again changes nothing, so a Search
     * that is settled twice (a reload, or the script settling it before the form is sent) is
     * one Search. As with counts, the user agent only leaves out obvious bots and is never kept.
     */
    public function recordSearch(string $search, string $words, int $resultsShown, bool $glossaryEntryShown, string $where, ?string $userAgent): void
    {
        if (! $this->isRecording() || Bots::isObvious($userAgent)) {
            return;
        }

        DB::table('search_records')->insertOrIgnore([
            'id' => $search,
            'words' => Cleaning::words($words),
            'results_shown' => $resultsShown,
            'glossary_entry_shown' => $glossaryEntryShown,
            'outcome' => ($resultsShown === 0 ? SearchOutcome::NoResults : SearchOutcome::NotOpened)->value,
            'day' => self::today()->toDateString(),
            'where_made' => $where,
        ]);
    }

    /**
     * Marks a recorded Search Opened, with the address of the result opened and its place in the
     * list. Only the first result opened counts, only a place that was shown, and only on the
     * Search's own day (a record never changes once its day is over, so a month's totals, added
     * up once it is over, always agree with its records); anything else (an unknown Search, a
     * place out of range, a results page opened again the next day) changes nothing.
     */
    public function searchOpened(string $search, int $place, string $openedResult, ?string $userAgent): void
    {
        if (! $this->isRecording() || Bots::isObvious($userAgent) || $place < 1) {
            return;
        }

        DB::table('search_records')
            ->where('id', $search)
            ->where('outcome', SearchOutcome::NotOpened->value)
            ->where('results_shown', '>=', $place)
            ->where('day', self::today()->toDateString())
            ->update([
                'outcome' => SearchOutcome::Opened->value,
                'opened_result' => $openedResult,
                'opened_place' => $place,
            ]);
    }

    /**
     * A Search the script settled (#46) whose results the reader then asked to see in full
     * (the form sent with the same words) on the same day: it now holds the results shown on the
     * results page, and its outcome follows them, unless it was already Opened. A Search from an
     * earlier day is left as it is.
     */
    public function searchShownAgain(string $search, int $resultsShown, bool $glossaryEntryShown): void
    {
        if (! $this->isRecording()) {
            return;
        }

        DB::table('search_records')
            ->where('id', $search)
            ->where('day', self::today()->toDateString())
            ->where('outcome', '!=', SearchOutcome::Opened->value)
            ->update([
                'results_shown' => $resultsShown,
                'glossary_entry_shown' => $glossaryEntryShown,
                'outcome' => ($resultsShown === 0 ? SearchOutcome::NoResults : SearchOutcome::NotOpened)->value,
            ]);
    }

    /**
     * Whether a Search was recorded under this id with these words (before cleaning) today, and
     * so may still be shown again (searchShownAgain()). A Search from an earlier day is over: the
     * same words searched again are a new Search.
     */
    public function hasSearch(string $search, string $words): bool
    {
        return DB::table('search_records')
            ->where('id', $search)
            ->where('words', Cleaning::words($words))
            ->where('day', self::today()->toDateString())
            ->exists();
    }

    /**
     * Every Search Record made between two days, both included, oldest day first.
     *
     * @return list<SearchRecord>
     */
    public function searches(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = DB::table('search_records')
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->orderBy('day')
            ->orderBy('words')
            ->get();

        return array_values($rows->map(fn (object $row): SearchRecord => self::searchRecord((array) $row))->all());
    }

    /**
     * Removes what rule 11 doesn't let the help centre keep (spec #30, "Keeping"): Search Records,
     * votes and their Feedback Reasons once they are more than KEEP_RECORDS_MONTHS old, and a
     * vote's comment once it is more than KEEP_COMMENTS_MONTHS old (the vote and its reason
     * stay). Every month that is over is added up first (monthlyTotals()), so nothing is pruned
     * before it is counted. Page View Counts and Still Stuck Counts hold nothing about anyone and
     * are kept for good. Run daily by help:prune-records.
     */
    public function prune(): Pruned
    {
        $today = self::today();
        $recordsFrom = $today->subMonthsNoOverflow(self::KEEP_RECORDS_MONTHS)->toDateString();
        $commentsFrom = $today->subMonthsNoOverflow(self::KEEP_COMMENTS_MONTHS)->toDateString();

        return DB::transaction(fn (): Pruned => new Pruned(
            monthsTotalled: $this->totalUp(),
            searchRecords: DB::table('search_records')->where('day', '<', $recordsFrom)->delete(),
            votes: DB::table('article_feedback')->where('day', '<', $recordsFrom)->delete(),
            comments: DB::table('article_feedback')->where('day', '<', $commentsFrom)->whereNotNull('comment')->update(['comment' => null]),
        ));
    }

    /**
     * A month's Search Records and votes added up, kept for good after the records are pruned:
     * numbers only, never words or comments. Null for a month that isn't over yet, and for one
     * before anything was recorded (before going public). A month that is over is added up
     * when first asked for, if the nightly job hasn't yet.
     */
    public function monthlyTotals(CarbonInterface $month): ?MonthlyTotals
    {
        $month = CarbonImmutable::parse($month->toDateString())->startOfMonth();
        DB::transaction(fn (): array => $this->totalUp());
        if (! DB::table('totalled_months')->where('month', $month->toDateString())->exists()) {
            return null;
        }

        $text = fn (object $row, string $column): string => is_scalar($row->{$column} ?? null) ? (string) $row->{$column} : '';

        return new MonthlyTotals(
            month: $month,
            searchTotals: array_values(DB::table('monthly_search_totals')->where('month', $month->toDateString())->get()
                ->map(fn (object $row): array => [
                    'where' => $text($row, 'where_made'),
                    'outcome' => SearchOutcome::from($text($row, 'outcome')),
                    'glossaryEntryShown' => (bool) $row->glossary_entry_shown,
                    'searches' => (int) $text($row, 'searches'),
                ])->all()),
            voteTotals: array_values(DB::table('monthly_vote_totals')->where('month', $month->toDateString())->get()
                ->map(fn (object $row): array => [
                    'article' => $text($row, 'article'),
                    'where' => $text($row, 'where_given'),
                    'answer' => Answer::from($text($row, 'answer')),
                    'reason' => $row->reason === null ? null : FeedbackReason::from($text($row, 'reason')),
                    'votes' => (int) $text($row, 'votes'),
                ])->all()),
        );
    }

    /**
     * The first UK day anything about readers was recorded: the day counting started, since
     * nothing is recorded until going public (isRecording()). Page View Counts are kept for good,
     * so it stays the same once older records are pruned. Null while nothing has been recorded.
     */
    public function countingSince(): ?CarbonImmutable
    {
        $days = array_filter(
            array_map(fn (string $table): mixed => DB::table($table)->min('day'), ['page_view_counts', 'still_stuck_counts', 'search_records', 'article_feedback']),
            fn (mixed $day): bool => $day !== null,
        );

        return $days === [] ? null : CarbonImmutable::parse(min(array_map(self::dateText(...), $days)), self::TIMEZONE);
    }

    /**
     * Adds up every month that is over and not yet added up, from the first month anything was
     * recorded (or the month after the last one added up) to last month, and gives back those
     * months (Y-m). Records never change once their day is past, so a month is added up once.
     *
     * @return list<string>
     */
    private function totalUp(): array
    {
        $firstDays = array_filter([
            DB::table('search_records')->min('day'),
            DB::table('article_feedback')->min('day'),
            ($last = DB::table('totalled_months')->max('month')) === null
                ? null : CarbonImmutable::parse(self::dateText($last))->addMonthNoOverflow()->toDateString(),
        ], fn (mixed $day): bool => $day !== null);
        if ($firstDays === []) {
            return [];
        }

        $thisMonth = self::today()->startOfMonth();
        $totalled = [];
        $from = CarbonImmutable::parse(min(array_map(self::dateText(...), $firstDays)))->startOfMonth();
        for ($month = $from; $month->lessThan($thisMonth); $month = $month->addMonthNoOverflow()) {
            if (DB::table('totalled_months')->where('month', $month->toDateString())->exists()) {
                continue;
            }
            $this->totalMonth($month);
            $totalled[] = $month->format('Y-m');
        }

        return $totalled;
    }

    private function totalMonth(CarbonImmutable $month): void
    {
        $days = [$month->toDateString(), $month->endOfMonth()->toDateString()];

        DB::table('monthly_search_totals')->insertUsing(
            ['month', 'where_made', 'outcome', 'glossary_entry_shown', 'searches'],
            DB::table('search_records')->whereBetween('day', $days)
                ->groupBy('where_made', 'outcome', 'glossary_entry_shown')
                ->selectRaw('?::date, where_made, outcome, glossary_entry_shown, count(*)', [$month->toDateString()]),
        );
        DB::table('monthly_vote_totals')->insertUsing(
            ['month', 'article', 'where_given', 'answer', 'reason', 'votes'],
            DB::table('article_feedback')->whereBetween('day', $days)
                ->groupBy('article', 'where_given', 'answer', 'reason')
                ->selectRaw('?::date, article, where_given, answer, reason, count(*)', [$month->toDateString()]),
        );
        DB::table('totalled_months')->insert(['month' => $month->toDateString()]);
    }

    private static function dateText(mixed $value): string
    {
        return substr(is_scalar($value) ? (string) $value : '', 0, 10);
    }

    /**
     * Keeps a month's uptime as the uptime monitor gives it (help:record-uptime, nightly): a
     * percentage, counted to the UK day $through. The month's figure is replaced each night
     * until $through is its last day. Uptime says nothing about readers, so it is kept before
     * going public too.
     */
    public function recordUptime(CarbonInterface $month, float $uptime, CarbonInterface $through): void
    {
        DB::table('monthly_uptime')->upsert(
            [
                'month' => CarbonImmutable::parse($month->toDateString())->startOfMonth()->toDateString(),
                'uptime' => round($uptime, 4),
                'through' => $through->toDateString(),
            ],
            ['month'],
            ['uptime', 'through'],
        );
    }

    /**
     * A month's uptime, for the Help Gaps Report's "Help centre stays up" measure: the share of
     * its uptime checks that passed, to the last day read so far (complete once the month is
     * over and read the night after). Null for a month never read from the monitor.
     */
    public function monthlyUptime(CarbonInterface $month): ?MonthlyUptime
    {
        $month = CarbonImmutable::parse($month->toDateString())->startOfMonth();
        $row = DB::table('monthly_uptime')->where('month', $month->toDateString())->first();

        return $row === null ? null : new MonthlyUptime(
            month: $month,
            uptime: (float) (is_numeric($row->uptime) ? $row->uptime : 0),
            through: CarbonImmutable::parse(self::dateText($row->through)),
        );
    }

    /**
     * The votes on one Article that still have a comment, on one day or any, oldest first, for
     * the Content Owner to find one a reader asks to have deleted (the privacy notice asks for
     * the Article and the date).
     *
     * @return list<ArticleVote>
     */
    public function comments(string $articleName, ?CarbonInterface $day = null): array
    {
        $from = $day ?? CarbonImmutable::parse('1970-01-01');

        return array_values(array_filter(
            $this->votes($from, $day ?? self::today()),
            fn (ArticleVote $vote): bool => $vote->article === $articleName && $vote->comment !== null,
        ));
    }

    /**
     * Deletes the comment of the vote with this reference on this Article, sooner than the six
     * months it would be kept (user story 101), and gives back the comment deleted. The vote and
     * its Feedback Reason stay. Null, changing nothing, when no vote on that Article with a
     * comment has that reference.
     */
    public function deleteComment(string $articleName, string $reference): ?string
    {
        if (! Str::isUuid($reference)) {
            return null;
        }

        return DB::transaction(function () use ($articleName, $reference): ?string {
            $comment = DB::table('article_feedback')->where('id', $reference)->where('article', $articleName)
                ->whereNotNull('comment')->lockForUpdate()->value('comment');
            if (! is_string($comment)) {
                return null;
            }
            DB::table('article_feedback')->where('id', $reference)->update(['comment' => null]);

            return $comment;
        });
    }

    /** @param  array<string, mixed>  $row */
    private static function searchRecord(array $row): SearchRecord
    {
        $text = fn (string $column): string => is_scalar($row[$column] ?? null) ? (string) $row[$column] : '';

        return new SearchRecord(
            words: $text('words'),
            resultsShown: (int) $text('results_shown'),
            glossaryEntryShown: (bool) $row['glossary_entry_shown'],
            outcome: SearchOutcome::from($text('outcome')),
            openedResult: $row['opened_result'] === null ? null : $text('opened_result'),
            openedPlace: $row['opened_place'] === null ? null : (int) $text('opened_place'),
            day: substr($text('day'), 0, 10),
            where: $text('where_made'),
        );
    }

    /**
     * @param  non-empty-literal-string  $table
     * @param  non-empty-literal-string  $keyColumn
     * @param  non-empty-literal-string  $countColumn
     */
    private function addOne(string $table, string $keyColumn, string $key, string $countColumn): void
    {
        if (! $this->isRecording()) {
            return;
        }

        DB::table($table)->upsert(
            [$keyColumn => $key, 'day' => self::today()->toDateString(), $countColumn => 1],
            [$keyColumn, 'day'],
            [$countColumn => DB::raw("\"{$table}\".\"{$countColumn}\" + 1")],
        );
    }

    /**
     * @param  non-empty-literal-string  $table
     * @param  non-empty-literal-string  $keyColumn
     * @param  non-empty-literal-string  $countColumn
     * @return array<string, int>
     */
    private function totals(string $table, string $keyColumn, string $countColumn, CarbonInterface $from, CarbonInterface $to): array
    {
        return DB::table($table)
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->groupBy($keyColumn)
            ->orderByRaw("sum(\"{$countColumn}\") desc")
            ->orderBy($keyColumn)
            ->selectRaw("\"{$keyColumn}\" as key, sum(\"{$countColumn}\") as total")
            ->pluck('total', 'key')
            ->map(fn (mixed $total): int => is_numeric($total) ? (int) $total : 0)
            ->all();
    }
}
