<?php

namespace App\Services;

use App\Models\CategorizationRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Catégorisation automatique : règles explicites (motifs « a|b|c »)
 * puis apprentissage sur l'historique (même libellé normalisé).
 */
class Categorizer
{
    private array $rules;

    private ?array $history = null;

    public function __construct(private User $user)
    {
        $this->rules = CategorizationRule::where('user_id', $user->id)
            ->orderBy('priority')->get(['pattern', 'category_id'])->all();
    }

    public static function normalize(?string $label): string
    {
        $s = mb_strtolower((string) $label);
        $s = preg_replace('/\b(cb|carte|prlv|sepa|vir|virement|paiement|achat|du|le|fact)\b/u', ' ', $s);
        $s = preg_replace('/[0-9]{2}[\/\.-][0-9]{2}([\/\.-][0-9]{2,4})?/', ' ', $s);
        $s = preg_replace('/[^a-zà-ÿ ]+/u', ' ', $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }

    public function guess(?string $description, string $type = 'expense'): ?int
    {
        $label = mb_strtolower((string) $description);
        if ($label === '') {
            return null;
        }
        foreach ($this->rules as $rule) {
            foreach (explode('|', mb_strtolower($rule->pattern)) as $p) {
                $p = trim($p);
                if ($p !== '' && str_contains($label, $p)) {
                    return (int) $rule->category_id;
                }
            }
        }
        if ($this->history === null) {
            $this->history = [];
            $rows = DB::table('transactions')->where('user_id', $this->user->id)
                ->whereNotNull('category_id')->orderBy('date')
                ->get(['description', 'category_id', 'type']);
            foreach ($rows as $r) {
                $this->history[$r->type.'|'.self::normalize($r->description)] = (int) $r->category_id;
            }
        }
        $key = $type.'|'.self::normalize($description);

        return $this->history[$key] ?? null;
    }
}
