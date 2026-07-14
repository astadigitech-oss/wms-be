<?php

namespace Tests\Feature;

use App\Http\Controllers\ColorRackController;
use Tests\TestCase;

class ColorRackControllerTest extends TestCase
{
    public function test_it_builds_union_expression_with_explicit_utf8mb4_collation(): void
    {
        $controller = new class extends ColorRackController {
            public function exposeCollationExpression(string $expression, string $alias): string
            {
                return $this->buildUnifiedUnionColumnExpression($expression, $alias);
            }
        };

        $this->assertSame(
            "CAST(new_name_product AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS new_name_product",
            $controller->exposeCollationExpression('new_name_product', 'new_name_product')
        );
    }
}
