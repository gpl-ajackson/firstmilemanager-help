<?php

namespace App\LaunchChecks\PersonalDetails;

/**
 * One email address, phone number or postcode found in some text, with the line it is on and
 * a key that is the same however it is written (capitals, spaces, "+44" or "0"), so a detail
 * in an Article can be matched with the same detail in the Trentside demo data.
 */
final readonly class PersonalDetail
{
    public const EMAIL = 'email';

    public const PHONE = 'phone';

    public const POSTCODE = 'postcode';

    /** What each kind is called in a finding. */
    private const NAMES = [self::EMAIL => 'an email address', self::PHONE => 'a phone number', self::POSTCODE => 'a postcode'];

    public function __construct(
        public string $kind,
        public string $text,
        public int $line,
        public int $offset,
    ) {}

    /** "email:jo@x.example", "phone:01154960123", "postcode:NG42JR". */
    public function key(): string
    {
        return $this->kind.':'.match ($this->kind) {
            self::EMAIL => mb_strtolower($this->text),
            self::POSTCODE => (string) preg_replace('/\s+/', '', mb_strtoupper($this->text)),
            default => self::nationalDigits($this->text),
        };
    }

    /** "an email address", "a phone number" or "a postcode". */
    public function name(): string
    {
        return self::NAMES[$this->kind];
    }

    /** A UK phone number's digits as dialled within the UK: "+44 (0)115 496 0123" is 01154960123. */
    private static function nationalDigits(string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', str_replace('(0)', '', $phone));

        return str_starts_with(ltrim($phone), '+44') ? '0'.substr($digits, 2) : $digits;
    }
}
