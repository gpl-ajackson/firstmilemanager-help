<?php

namespace App\Records;

use Carbon\CarbonImmutable;

/**
 * One Article Feedback vote as Records keeps it: what was answered about which Article, the
 * Article's Checked On date at the time, the day and where. Never who. The reference is the
 * vote's random id: the Content Owner names a comment by it to delete it (help:delete-comment).
 */
final readonly class ArticleVote
{
    public function __construct(
        public string $article,
        public Answer $answer,
        public ?FeedbackReason $reason,
        public ?string $comment,
        public CarbonImmutable $checkedOn,
        public CarbonImmutable $day,
        public string $where,
        public string $reference,
    ) {}

    /**
     * Everything but the reference, which is random.
     *
     * @return array{article: string, answer: string, reason: ?string, comment: ?string, checked_on: string, day: string, where: string}
     */
    public function toArray(): array
    {
        return [
            'article' => $this->article,
            'answer' => $this->answer->value,
            'reason' => $this->reason?->value,
            'comment' => $this->comment,
            'checked_on' => $this->checkedOn->toDateString(),
            'day' => $this->day->toDateString(),
            'where' => $this->where,
        ];
    }
}
