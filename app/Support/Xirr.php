<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Taux de rendement interne pour flux irréguliers (équivalent XIRR d'Excel).
 * Convention : apports négatifs, valeur finale positive.
 */
class Xirr
{
    /**
     * @param  array<int, array{0: string, 1: float}>  $flows  [date Y-m-d, montant]
     */
    public static function compute(array $flows, float $guess = 0.1): ?float
    {
        if (count($flows) < 2) {
            return null;
        }
        usort($flows, fn ($a, $b) => strcmp($a[0], $b[0]));
        $t0 = Carbon::parse($flows[0][0]);
        $series = array_map(fn ($f) => [$t0->diffInDays(Carbon::parse($f[0])) / 365.0, $f[1]], $flows);

        $hasPos = $hasNeg = false;
        foreach ($series as [, $v]) {
            $v > 0 ? $hasPos = true : ($v < 0 ? $hasNeg = true : null);
        }
        if (! $hasPos || ! $hasNeg) {
            return null;
        }
        // Moins d'un mois d'historique : rendement non significatif.
        if (end($series)[0] < 1 / 12) {
            return null;
        }

        $npv = function (float $r) use ($series): float {
            $s = 0.0;
            foreach ($series as [$t, $v]) {
                $s += $v / (1 + $r) ** $t;
            }

            return $s;
        };

        // Newton-Raphson
        $r = $guess;
        for ($i = 0; $i < 100; $i++) {
            $f = 0.0;
            $df = 0.0;
            foreach ($series as [$t, $v]) {
                $f += $v / (1 + $r) ** $t;
                $df -= $t * $v / (1 + $r) ** ($t + 1);
            }
            if (abs($df) < 1e-12) {
                break;
            }
            $next = $r - $f / $df;
            if (! is_finite($next) || $next <= -0.9999) {
                break;
            }
            if (abs($next - $r) < 1e-9) {
                return $next;
            }
            $r = $next;
        }

        // Repli : dichotomie sur [-99 %, +1000 %]
        $lo = -0.9999;
        $hi = 10.0;
        $flo = $npv($lo);
        $fhi = $npv($hi);
        if ($flo * $fhi > 0) {
            return null;
        }
        for ($i = 0; $i < 200; $i++) {
            $mid = ($lo + $hi) / 2;
            $fm = $npv($mid);
            if (abs($fm) < 1e-7) {
                return $mid;
            }
            ($flo * $fm < 0) ? [$hi, $fhi] = [$mid, $fm] : [$lo, $flo] = [$mid, $fm];
        }

        return ($lo + $hi) / 2;
    }
}
