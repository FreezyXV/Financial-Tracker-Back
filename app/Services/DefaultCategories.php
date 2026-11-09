<?php

namespace App\Services;

use App\Models\CategorizationRule;
use App\Models\Category;
use App\Models\User;

class DefaultCategories
{
    /** [nom, type, couleur, icône, essentielle] */
    public const LIST = [
        ['Salaire', 'income', '#1f7a5c', 'briefcase', false],
        ['Freelance', 'income', '#2f8f6f', 'laptop', false],
        ['Primes & bonus', 'income', '#3a9d7c', 'award', false],
        ['Allocations & aides', 'income', '#4aa889', 'shield', false],
        ['Remboursements', 'income', '#5bb396', 'rotate', false],
        ['Revenus locatifs', 'income', '#6cbda3', 'home', false],
        ['Autres revenus', 'income', '#7dc7b0', 'plus', false],
        ['Logement', 'expense', '#1b2a4a', 'home', true],
        ['Énergie & eau', 'expense', '#2b3f66', 'bolt', true],
        ['Courses', 'expense', '#3d5a80', 'cart', true],
        ['Transport', 'expense', '#4f6d94', 'train', true],
        ['Santé', 'expense', '#6a8bb0', 'heart', true],
        ['Assurances', 'expense', '#7f9cc0', 'shield', true],
        ['Télécom & internet', 'expense', '#93acc9', 'wifi', true],
        ['Impôts & taxes', 'expense', '#5c4b8a', 'landmark', true],
        ['Éducation', 'expense', '#7a5ca8', 'book', true],
        ['Restaurants & sorties', 'expense', '#b23a3a', 'fork', false],
        ['Abonnements', 'expense', '#c25450', 'repeat', false],
        ['Loisirs & culture', 'expense', '#cf6d5f', 'ticket', false],
        ['Shopping', 'expense', '#d9846f', 'bag', false],
        ['Voyages', 'expense', '#a07a2c', 'plane', false],
        ['Sport & bien-être', 'expense', '#b8913f', 'activity', false],
        ['Cadeaux & dons', 'expense', '#c9a65a', 'gift', false],
        ['Frais bancaires', 'expense', '#8a8f98', 'bank', false],
        ['Divers', 'expense', '#a3a8b0', 'dots', false],
    ];

    /** Règles de catégorisation de départ (libellés bancaires français courants). */
    public const RULES = [
        'Courses' => 'carrefour|leclerc|auchan|lidl|monoprix|franprix|intermarche|super u|casino|picard|aldi|naturalia|biocoop',
        'Restaurants & sorties' => 'restaurant|deliveroo|uber eats|just eat|mcdonald|burger king|starbucks|kfc|bistro|brasserie|sushi|boulangerie',
        'Transport' => 'sncf|ratp|navigo|uber|bolt|total energies|esso|bp |shell|blablacar|lime|velib|autoroute',
        'Abonnements' => 'netflix|spotify|disney|amazon prime|canal+|apple.com|deezer|youtube premium|chatgpt|openai|adobe',
        'Télécom & internet' => 'free mobile|orange|sfr|bouygues|sosh|red by sfr|freebox',
        'Énergie & eau' => 'edf|engie|totalenergies elec|veolia|suez|ekwateur',
        'Santé' => 'pharmacie|doctolib|medecin|dentiste|mutuelle|ameli|cpam',
        'Logement' => 'loyer|foncia|nexity|syndic|credit immo',
        'Shopping' => 'amazon|fnac|zara|decathlon|ikea|h&m|uniqlo|leroy merlin|cdiscount|vinted',
        'Impôts & taxes' => 'dgfip|impots|tresor public|taxe',
        'Frais bancaires' => 'frais|commission|cotisation carte|agios',
        'Salaire' => 'salaire|paie|payroll',
    ];

    /** Création en deux requêtes groupées (au lieu d'une par ligne) : rapide même avec une base distante. */
    public static function seed(User $user): void
    {
        $now = now();
        Category::insert(array_map(fn ($c) => [
            'user_id' => $user->id, 'name' => $c[0], 'type' => $c[1], 'color' => $c[2], 'icon' => $c[3],
            'is_essential' => $c[4], 'created_at' => $now, 'updated_at' => $now,
        ], self::LIST));
        $ids = Category::where('user_id', $user->id)->pluck('id', 'name');
        $rules = [];
        $p = 10;
        foreach (self::RULES as $name => $pattern) {
            $rules[] = ['user_id' => $user->id, 'category_id' => $ids[$name], 'pattern' => $pattern,
                'priority' => $p += 10, 'created_at' => $now, 'updated_at' => $now];
        }
        CategorizationRule::insert($rules);
    }
}
