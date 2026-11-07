<?php

namespace Tests\Unit;

use App\Support\Xirr;
use PHPUnit\Framework\TestCase;

class XirrTest extends TestCase
{
    public function test_simple_annual_return(): void
    {
        $r = Xirr::compute([['2024-01-01', -1000], ['2025-01-01', 1100]]);
        $this->assertEqualsWithDelta(0.10, $r, 0.002);
    }

    public function test_negative_return(): void
    {
        $r = Xirr::compute([['2024-01-01', -1000], ['2024-07-01', -1000], ['2025-01-01', 1800]]);
        $this->assertLessThan(0, $r);
    }

    public function test_returns_null_without_sign_change_or_short_history(): void
    {
        $this->assertNull(Xirr::compute([['2024-01-01', -1000], ['2025-01-01', -100]]));
        $this->assertNull(Xirr::compute([['2024-01-01', -1000], ['2024-01-10', 1010]]));
    }
}
