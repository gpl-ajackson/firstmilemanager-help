<?php

namespace App\LaunchChecks;

/** Everything one run of the Launch Checks found, in the order the checks found it. */
final class Findings
{
    /** @var list<Finding> */
    private array $findings = [];

    /** @var list<string> Checks that could not run here, and why. */
    private array $notRun = [];

    public function add(Finding $finding): void
    {
        $this->findings[] = $finding;
    }

    public function fail(string $rule, string $file, ?int $line, string $message): void
    {
        $this->add(Finding::failure($rule, $file, $line, $message));
    }

    public function report(string $rule, string $file, ?int $line, string $message): void
    {
        $this->add(Finding::report($rule, $file, $line, $message));
    }

    public function notRun(string $why): void
    {
        $this->notRun[] = $why;
    }

    /** @return list<Finding> */
    public function failures(): array
    {
        return array_values(array_filter($this->findings, fn (Finding $finding): bool => $finding->fails));
    }

    /** @return list<Finding> */
    public function reports(): array
    {
        return array_values(array_filter($this->findings, fn (Finding $finding): bool => ! $finding->fails));
    }

    /** @return list<string> */
    public function checksNotRun(): array
    {
        return $this->notRun;
    }

    public function passed(): bool
    {
        return $this->failures() === [];
    }
}
