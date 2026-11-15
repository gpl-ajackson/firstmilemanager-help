<?php

namespace App\LaunchChecks;

use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Error;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Return_;
use PhpParser\ParserFactory;

/**
 * Reads the array a PHP file returns, such as a Laravel language file, without running it: the
 * main project's files are read as text, so a check never executes its code.
 */
final class PhpArrayFile
{
    /** @return array<mixed>|null null when the file is missing or returns no plain array. */
    public static function read(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            $statements = (new ParserFactory)->createForHostVersion()->parse((string) file_get_contents($path)) ?? [];
        } catch (Error) {
            return null;
        }

        // Anything that isn't a constant (a function call, say) reads as null rather than running.
        $evaluator = new ConstExprEvaluator(fn (Expr $expr): mixed => null);
        foreach ($statements as $statement) {
            if ($statement instanceof Return_ && $statement->expr !== null) {
                try {
                    $value = $evaluator->evaluateDirectly($statement->expr);
                } catch (ConstExprEvaluationException) {
                    return null;
                }

                return is_array($value) ? $value : null;
            }
        }

        return null;
    }
}
