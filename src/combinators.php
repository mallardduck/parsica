<?php declare(strict_types=1);
/**
 * This file is part of the Parsica library.
 *
 * Copyright (c) 2020 Mathias Verraes <mathias@verraes.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Verraes\Parsica;

use InvalidArgumentException;
use Verraes\Parsica\Internal\Assert;
use Verraes\Parsica\Internal\Fail;
use Verraes\Parsica\Internal\Succeed;
use function Verraes\Parsica\Internal\FP\foldl;

/**
 * Identity parser, returns the Parser as is.
 *
 * @psalm-param Parser<T> $parser
 *
 * @psalm-return Parser<T>
 * @api
 *
 * @template T
 *
 */
function identity(Parser $parser): Parser
{
    return $parser;
}

/**
 * A parser that will have the argument as its output, no matter what the input was. It doesn't consume any input.
 *
 * @psalm-param T $output
 *
 * @psalm-return Parser<T>
 * @api
 *
 * @template T
 *
 */
function pure($output): Parser
{
    return Parser::make("<pure>", fn(Stream $input) => new Succeed($output, $input));
}

/**
 * Optionally parse something, but still succeed if the thing is not there
 *
 * @psalm-param Parser<T> $parser
 *
 * @psalm-return Parser<T|null>
 * @api
 * @template T
 */
function optional(Parser $parser): Parser
{
    return either($parser, succeed())->label("optional " . $parser->getLabel());
}

/**
 * Create a parser that takes the output from the first parser (if successful) and feeds it to the callable. The callable
 * must return another parser. If the first parser fails, the first parser is returned.
 *
 * This is a monadic bind aka flatmap.
 *
 * @psalm-param Parser<T1> $parser
 * @psalm-param callable(T1) : Parser<T2> $f
 *
 * @psalm-return Parser<T2>
 * @api
 * @template T1
 * @template T2
 *
 */
function bind(Parser $parser, callable $f): Parser
{
    /** @psalm-var Parser<T2> $finalParser */
    $finalParser = Parser::make($parser->getLabel(), function (Stream $input) use ($parser, $f) : ParseResult {
        $result = $parser->run($input)->map($f);
        if ($result->isFail()) {
            return $result;
        }
        $p2 = $result->output();
        return $result->continueWith($p2);
    });
    return $finalParser;
}

/**
 * Sequential application. Given a parser which outputs a callable, return a new parser that applies the callable on the
 * output of the second parser.
 *
 * The first parser must be of type Parser<callable(T1):T2>. {@see pure()} can be used to wrap a callable in a Parser.
 *
 * Callables with more than 1 argument need to be curried: pure(curry(fn($x, $y)))->apply($parser2)->apply($parser3)
 *
 * @template T1
 * @template T2
 * @psalm-param Parser<callable(T1):T2> $parser1
 * @psalm-param Parser<T1> $parser2
 * @psalm-return Parser<T2>
 * @api
 */
function apply(Parser $parser1, Parser $parser2): Parser
{
    /** @psalm-var Parser<T2> $parser */
    $parser = Parser::make($parser1->getLabel(), function (Stream $input) use ($parser2, $parser1) : ParseResult {
        $r1 = $parser1->run($input);
        if ($r1->isFail()) {
            return $r1;
        }
        $f = $r1->output();
        Assert::isCallable($f, "apply() can only be used when the output of the first parser is a callable with 1 argument. Use currying for functions with more than 1 argument.");
        // @todo assert that the arity of $f == 1
        return $r1->continueWith($parser2)->map($f);
    });
    return $parser;
}


/**
 * Parse something, then follow by something else. Ignore the result of the first parser and return the result of the
 * second parser.
 *
 * @psalm-param Parser<T1> $first
 * @psalm-param Parser<T2> $second
 *
 * @psalm-return Parser<T2>
 * @template T1
 * @template T2
 * @api
 * @see Parser::sequence()
 *
 */
function sequence(Parser $first, Parser $second): Parser
{
    return bind($first, /** @psalm-param mixed $_ */ fn($_) => $second);
}

/**
 * Sequence two parsers, and return the output of the first one.
 *
 * @template T1
 * @template T2
 * @psalm-param Parser<T1> $first
 * @psalm-param Parser<T2> $second
 * @psalm-return Parser<T2>
 * @api
 */
function keepFirst(Parser $first, Parser $second): Parser
{
    return bind(
        $first,
        /** @psalm-suppress MissingClosureParamType */
        fn($a): Parser => sequence($second, pure($a))
    );
}

/**
 * Sequence two parsers, and return the output of the second one.
 *
 * @api
 */
function keepSecond(Parser $first, Parser $second): Parser
{
    return sequence($first, $second);
}

/**
 * Either parse the first thing or the second thing
 *
 * @psalm-param Parser<T1> $first
 * @psalm-param Parser<T2> $second
 *
 * @psalm-return Parser<T1|T2>
 * @api
 *
 * @see Parser::or()
 *
 * @template T1
 * @template T2
 *
 */
function either(Parser $first, Parser $second): Parser
{
    $label = $first->getLabel() . " or " . $second->getLabel();
    return Parser::make($label, function (Stream $input) use ($second, $first, $label): ParseResult {
        // @todo Megaparsec doesn't do automatic rollback, for performance reasons, and requires the user to add try
        //       combinators. We could mimic that behaviour as it is probably more performant
        $r1 = $first->run($input);
        if ($r1->isSuccess()) {
            return $r1;
        }
        $r2 = $second->run($input);

        if ($r2->isSuccess()) {
            return $r2;
        }

        return new Fail($label, $r2->got());
    });
}


/**
 * Combine the parser with another parser of the same type, which will cause the results to be appended.
 *
 * @psalm-param Parser<T|null> $left
 * @psalm-param Parser<T|null> $right
 *
 * @psalm-return Parser<T|null>
 * @api
 * @template T
 *
 */
function append(Parser $left, Parser $right): Parser
{
    return Parser::make($right->getLabel(), function (Stream $input) use ($left, $right): ParseResult {
        $r1 = $left->run($input);
        $r2 = $r1->continueWith($right);
        return $r1->append($r2);
    });
}

/**
 * Append all the passed parsers.
 *
 * @psalm-param list<Parser<T|null>> $parsers
 * @psalm-return Parser<T|null>
 * @api
 * @template T
 * @psalm-suppress MixedReturnStatement
 * @psalm-suppress MixedInferredReturnType
 */
function assemble(Parser ...$parsers): Parser
{
    Assert::atLeastOneArg($parsers, "assemble()");
    $first = array_shift($parsers);
    /** @psalm-suppress InvalidArgument */
    return array_reduce($parsers, fn(Parser $p1, Parser $p2): Parser => append($p1, $p2), $first);
}

/**
 * Parse into an array that consists of the results of all parsers.
 *
 * @psalm-param list<Parser<mixed>> $parsers
 * @psalm-return Parser<mixed>
 * @api
 */
function collect(Parser ...$parsers): Parser
{
    $toArray =
        /**
         * @psalm-param mixed $v
         * @psalm-return list<mixed>
         */
        fn($v): array => [$v];
    $arrayParsers = array_map(
        fn(Parser $parser): Parser => map($parser, $toArray),
        $parsers
    );
    return assemble(...$arrayParsers);
}

/**
 * Tries each parser one by one, returning the result of the first one that succeeds.
 *
 * @psalm-param non-empty-list<Parser<mixed>> $parsers
 * @psalm-return Parser<mixed>
 * @api
 */
function any(Parser ...$parsers): Parser
{
    if (empty($parsers)) {
        throw new InvalidArgumentException("any() expects at least one parser");
    }

    $labels = array_map(fn(Parser $p): string => $p->getLabel(), $parsers);
    $label = implode(' or ', $labels);

    return foldl(
        $parsers,
        fn(Parser $first, Parser $second): Parser => either($first, $second),
        fail("")
    )->label($label);
}

/**
 * Tries each parser one by one, returning the result of the first one that succeeds.
 *
 * Alias for {@see any()}
 *
 * @psalm-param non-empty-list<Parser<mixed>> $parsers
 * @psalm-return Parser<mixed>
 * @api
 */
function choice(Parser ...$parsers): Parser
{
    return any(...$parsers);
}

/**
 * One or more repetitions of Parser, with the outputs appended.
 *
 * @api
 * @psalm-param Parser<T> $parser
 * @psalm-return Parser<T>
 * @template T
 * @psalm-suppress MixedArgumentTypeCoercion
 */
function atLeastOne(Parser $parser): Parser
{
    return Parser::make(
        "at least one " . $parser->getLabel(),
        function (Stream $input) use ($parser) : ParseResult {
            $result = $parser->run($input);
            if ($result->isFail()) {
                return $result;
            }
            $final = new Succeed(null, $result->remainder());
            while ($result->isSuccess()) {
                $final = $final->append($result);
                $result = $parser->continueFrom($result);
            }
            return $final;
        }
    );
}

/**
 * Zero or more repetitions of Parser, with the outputs appended.
 *
 * @deprecated @TODO Untested
 *
 * @api
 * @psalm-param Parser<T> $parser
 * @psalm-return Parser<T>
 * @template T
 * @psalm-suppress MixedArgumentTypeCoercion
 */
function zeroOrMore(Parser $parser): Parser
{
    return Parser::make(
        "zero or more " . $parser->getLabel(),
        function (Stream $input) use ($parser) : ParseResult {
            $result = new Succeed(null, $input);
            $final = $result;
            while ($result->isSuccess()) {
                $final = $final->append($result);
                $result = $parser->continueFrom($result);
            }
            return $final;
        }
    );
}

/**
 * Parse something exactly n times
 *
 * @template T
 *
 * @psalm-param Parser<T> $parser
 *
 * @psalm-return Parser<T>
 * @api
 */
function repeat(int $n, Parser $parser): Parser
{
    return foldl(
        array_fill(0, $n - 1, $parser),
        fn(Parser $l, Parser $r): Parser => append($l, $r),
        $parser
    )->label("$n times " . $parser->getLabel());
}

/**
 * Parse something exactly n times and return as an array
 *
 * @TODO This doesn't feel very elegant.
 *
 * @template T
 *
 * @psalm-param Parser<T> $parser
 *
 * @psalm-return Parser<T>
 * @api
 */
function repeatList(int $n, Parser $parser): Parser
{
    $parser = map($parser, /** @psalm-param mixed $output */ fn($output): array => [$output]);

    $parsers = array_fill(0, $n - 1, $parser);
    return foldl(
        $parsers,
        fn(Parser $l, Parser $r): Parser => append($l, $r),
        $parser
    )->label("$n times " . $parser->getLabel());
}

/**
 * Parse something one or more times, and output an array of the successful outputs.
 *
 * @template T
 *
 * @psalm-param Parser<T> $parser
 * @psalm-return Parser<list<T>>
 *
 * @api
 */
function some(Parser $parser): Parser
{
    return map(
            collect($parser, many($parser)),
            /**
             * @template T
             * @psalm-param array{0: T, 1: list<T>} $o
             * @psalm-return list<T>
             */
            fn(array $o):array => array_merge([$o[0]], $o[1])
    );
}

/**
 * Parse something zero or more times, and output an array of the successful outputs.
 *
 * @template T
 *
 * @psalm-param Parser<T> $parser
 * @psalm-return Parser<list<T>>
 * @api
 */
function many(Parser $parser): Parser
{
    $rec = recursive();
    $rec->recurse(
        any(
            map(
                collect($parser, $rec),
                /**
                 * @template T
                 * @psalm-param array{0: T, 1: list<T>} $o
                 * @psalm-return list<T>
                 */
                fn(array $o): array => array_merge([$o[0]], $o[1])
            ),
            pure([]),
        )
    );
    return $rec;
}

/**
 * Parse $open, followed by $middle, followed by $close, and return the result of $middle. Useful for eg. "(value)".
 *
 * @template TO
 * @template TM
 * @template TC
 *
 * @psalm-param Parser<TO> $open
 * @psalm-param Parser<TC> $close
 * @psalm-param Parser<TM> $middle
 *
 * @psalm-return Parser<TM>
 * @api
 */
function between(Parser $open, Parser $close, Parser $middle): Parser
{
    return keepSecond($open, keepFirst($middle, $close))
        ->label("keep {$middle->getLabel()} BETWEEN {$open->getLabel()} and {$close->getLabel()}");
}

/**
 * Parses zero or more occurrences of $parser, separated by $separator. Returns a list of values.
 *
 * The sepBy parser always succeed, even if it doesn't find anything. Use {@see sepBy1()} if you want it to find at
 * least one value.
 *
 * @template TSeparator
 * @template T
 *
 * @psalm-param Parser<TSeparator> $separator
 * @psalm-param Parser<T>  $parser
 *
 * @psalm-return Parser<list<T>>
 *
 * @api
 */
function sepBy(Parser $separator, Parser $parser): Parser
{
    return sepBy1($separator, $parser)->or(pure([]));
}


/**
 * Parses one or more occurrences of $parser, separated by $separator. Returns a list of values.
 *
 * @template TS
 * @template T
 *
 * @psalm-param Parser<TS> $separator
 * @psalm-param Parser<T>  $parser
 *
 * @psalm-return Parser<list<T>>
 *
 * @psalm-suppress MissingClosureReturnType
 *
 * @api
 */
function sepBy1(Parser $separator, Parser $parser): Parser
{
    /** @psalm-suppress MissingClosureParamType */
    $prepend = fn($x) => fn(array $xs): array => array_merge([$x], $xs);
    $label = $parser->getLabel() . ", separated by " . $separator->getLabel();
    return pure($prepend)->apply($parser)->apply(many($separator->sequence($parser)))->label($label);
}

/**
 * Parses 2 or more occurrences of $parser, separated by $separator. Returns a list of values.
 *
 * @template TS
 * @template T
 *
 * @psalm-param Parser<TS> $separator
 * @psalm-param Parser<T>  $parser
 *
 * @psalm-return Parser<list<T>>
 *
 * @psalm-suppress MissingClosureReturnType
 *
 * @api
 */
function sepBy2(Parser $separator, Parser $parser): Parser
{
    /** @psalm-suppress MissingClosureParamType */
    $prepend = fn($x) => fn(array $xs): array => array_merge([$x], $xs);
    $label = "at least two of (" . $parser->getLabel() . "), separated by " . $separator->getLabel();
    return pure($prepend)->apply(keepFirst($parser, $separator))->apply(sepBy1($separator, $parser))->label($label);
}

/**
 * notFollowedBy only succeeds when $parser fails. It never consumes any input.
 *
 * Example:
 *
 * `string("print")` will also match "printXYZ"
 *
 * `keepFirst(string("print"), notFollowedBy(alphaNumChar()))` will match "print something" but not "printXYZ something"
 *
 * @template T
 * @psalm-param Parser<T> $parser
 * @psalm-return Parser<T>
 * @see Parser::notFollowedBy()
 *
 * @api
 */
function notFollowedBy(Parser $parser): Parser
{
    /** @psalm-var Parser<string> $p */
    $label = "notFollowedBy({$parser->getLabel()})";

    $p = Parser::make($label, function (Stream $input) use ($label, $parser): ParseResult {
        $result = $parser->run($input);
        return $result->isSuccess()
            ? new Fail($label, $input)
            : new Succeed("", $input);
    });
    return $p;
}

/**
 * Map a function over the parser (which in turn maps it over the result).
 *
 * @template T1
 * @template T2
 * @psalm-param callable(T1) : T2 $transform
 * @psalm-return Parser<T2>
 * @api
 */
function map(Parser $parser, callable $transform): Parser
{
    return Parser::make($parser->getLabel(), fn(Stream $input): ParseResult => $parser->run($input)->map($transform));
}

/**
 * If $parser succeeds (either consuming input or not), lookAhead behaves like $parser succeeded without consuming
 * anything. If $parser fails, lookAhead has no effect, i.e. it will fail to consume input if $parser fails consuming
 * input.
 *
 * @template T
 * @psalm-param Parser<T> $parser
 * @psalm-return Parser<T>
 *
 * @api
 */
function lookAhead(Parser $parser): Parser
{
    return Parser::make(
        $parser->getLabel(),
        fn(Stream $input): ParseResult => $parser->run($input)->isSuccess()
            ? new Succeed("", $input)
            : new Fail("lookAhead", $input)
    );
}
