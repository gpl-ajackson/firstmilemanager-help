<?php

namespace App\Records;

/**
 * One Search Record as Records gives it back (to the Help Gaps Report): everything it holds,
 * which is nothing about the reader.
 */
final readonly class SearchRecord
{
    public function __construct(
        /** The words searched for, cleaned of personal details. */
        public string $words,
        public int $resultsShown,
        public bool $glossaryEntryShown,
        public SearchOutcome $outcome,
        /** The address of the result opened (an Article's, without any Section), when Opened. */
        public ?string $openedResult,
        /** Its place in the list, from 1, when Opened. */
        public ?int $openedPlace,
        /** The UK day, as YYYY-MM-DD; a Search Record holds no time. */
        public string $day,
        /** Where the Search was made ("help centre" in SR1). */
        public string $where,
    ) {}

    /** @return array{words: string, results_shown: int, glossary_entry_shown: bool, outcome: string, opened_result: string|null, opened_place: int|null, day: string, where: string} */
    public function toArray(): array
    {
        return [
            'words' => $this->words,
            'results_shown' => $this->resultsShown,
            'glossary_entry_shown' => $this->glossaryEntryShown,
            'outcome' => $this->outcome->value,
            'opened_result' => $this->openedResult,
            'opened_place' => $this->openedPlace,
            'day' => $this->day,
            'where' => $this->where,
        ];
    }
}
