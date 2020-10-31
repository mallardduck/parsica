<?php declare(strict_types=1);
/*
 * This file is part of the Parsica library.
 *
 * Copyright (c) 2020 Mathias Verraes <mathias@verraes.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Verraes\Parsica\Expression;

use Verraes\Parsica\Parser;
use function Verraes\Parsica\choice;
use function Verraes\Parsica\collect;
use function Verraes\Parsica\map;

/**
 * @internal
 * @template TSymbol
 * @template TExpressionAST
 */
final class NonAssoc implements ExpressionType
{
    /**
     * @psalm-var BinaryOperator<TSymbol, TExpressionAST>
     */
    private BinaryOperator $operator;

    /**
     * @psalm-param BinaryOperator<TSymbol, TExpressionAST> $operator
     */
    function __construct(BinaryOperator $operator)
    {
        $this->operator = $operator;
    }

    public function buildPrecedenceLevel(Parser $previousPrecedenceLevel): Parser
    {
        return choice(
            map(
                collect(
                    $previousPrecedenceLevel,
                    $this->operator->symbol(),
                    $previousPrecedenceLevel
                ),

                /**
                 * @psalm-return TExpressionAST
                 */
                fn(array $o) => $this->operator->transform()($o[0], $o[2])),
            $previousPrecedenceLevel
        );
    }
}
