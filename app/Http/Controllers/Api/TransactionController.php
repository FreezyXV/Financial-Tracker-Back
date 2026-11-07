<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\AlertService;
use App\Services\Categorizer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    private function filtered(Request $request)
    {
        $q = Transaction::where('user_id', $request->user()->id);
        if ($m = $request->query('month')) {
            $start = Carbon::parse($m.'-01');
            $q->whereBetween('date', [$start->format('Y-m-d'), $start->copy()->endOfMonth()->format('Y-m-d')]);
        }
        if ($from = $request->query('from')) {
            $q->where('date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->where('date', '<=', $to);
        }
        if ($acc = $request->query('account_id')) {
            $q->where(fn ($w) => $w->where('account_id', $acc)->orWhere('transfer_account_id', $acc));
        }
        if ($request->has('category_id')) {
            $cat = $request->query('category_id');
            $cat === 'none' ? $q->whereNull('category_id')->where('type', '!=', 'transfer') : $q->where('category_id', $cat);
        }
        if ($type = $request->query('type')) {
            $q->where('type', $type);
        }
        if ($s = $request->query('search')) {
            $q->where(fn ($w) => $w->where('description', 'like', "%$s%")->orWhere('notes', 'like', "%$s%"));
        }
        if ($min = $request->query('min')) {
            $q->where('amount', '>=', $min);
        }
        if ($max = $request->query('max')) {
            $q->where('amount', '<=', $max);
        }

        return $q;
    }

    public function index(Request $request)
    {
        $q = $this->filtered($request);
        $totals = (clone $q)->selectRaw("
            SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS income,
            SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expense,
            COUNT(*) AS count")->first();

        $page = $q->with(['category:id,name,color,icon,type', 'account:id,name,type,color', 'transferAccount:id,name'])
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 50), 500));

        return response()->json(array_merge($page->toArray(), [
            'totals' => [
                'income' => round((float) $totals->income, 2),
                'expense' => round((float) $totals->expense, 2),
                'net' => round((float) $totals->income - (float) $totals->expense, 2),
                'count' => (int) $totals->count,
            ],
        ]));
    }

    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'account_id' => "$req|integer",
            'category_id' => 'nullable|integer',
            'transfer_account_id' => 'nullable|integer|required_if:type,transfer|different:account_id',
            'type' => [$req, Rule::in(['income', 'expense', 'transfer'])],
            'amount' => "$req|numeric|gt:0",
            'date' => "$req|date",
            'description' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:40',
        ];
    }

    private function checkRelations(Request $request, array $data): void
    {
        $this->assertOwnedId($request, Account::class, $data['account_id'] ?? null);
        $this->assertOwnedId($request, Account::class, $data['transfer_account_id'] ?? null);
        $this->assertOwnedId($request, Category::class, $data['category_id'] ?? null);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $this->checkRelations($request, $data);
        if ($data['type'] !== 'transfer') {
            $data['transfer_account_id'] = null;
            if (empty($data['category_id'])) {
                $data['category_id'] = (new Categorizer($request->user()))->guess($data['description'] ?? null, $data['type']);
            }
        } else {
            $data['category_id'] = null;
        }
        $tx = $request->user()->transactions()->create($data);
        AlertService::afterTransaction($request->user(), $tx->load('category'));

        return response()->json($tx->load(['category:id,name,color,icon,type', 'account:id,name,type,color']), 201);
    }

    public function show(Request $request, Transaction $transaction)
    {
        return $this->owned($request, $transaction)->load(['category', 'account', 'transferAccount']);
    }

    public function update(Request $request, Transaction $transaction)
    {
        $this->owned($request, $transaction);
        $data = $request->validate($this->rules(true));
        $this->checkRelations($request, $data);
        if (($data['type'] ?? $transaction->type) !== 'transfer') {
            $data['transfer_account_id'] = null;
        }
        $transaction->update($data);
        AlertService::afterTransaction($request->user(), $transaction->load('category'));

        return $transaction->load(['category:id,name,color,icon,type', 'account:id,name,type,color']);
    }

    public function destroy(Request $request, Transaction $transaction)
    {
        $this->owned($request, $transaction)->delete();

        return response()->noContent();
    }

    /** Actions groupées : catégoriser ou supprimer plusieurs transactions. */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'action' => ['required', Rule::in(['categorize', 'delete'])],
            'category_id' => 'nullable|integer|required_if:action,categorize',
        ]);
        $this->assertOwnedId($request, Category::class, $data['category_id'] ?? null);
        $q = Transaction::where('user_id', $request->user()->id)->whereIn('id', $data['ids']);
        $n = $data['action'] === 'delete' ? $q->delete() : $q->where('type', '!=', 'transfer')->update(['category_id' => $data['category_id']]);

        return ['affected' => $n];
    }

    /**
     * Import de lignes normalisées par le front (CSV bancaire déjà mappé).
     * Montant signé : négatif = dépense. Dédoublonnage par empreinte.
     */
    public function import(Request $request)
    {
        $data = $request->validate([
            'account_id' => 'required|integer',
            'rows' => 'required|array|min:1|max:5000',
            'rows.*.date' => 'required|date',
            'rows.*.description' => 'nullable|string|max:255',
            'rows.*.amount' => 'required|numeric',
            'rows.*.category_id' => 'nullable|integer',
        ]);
        $this->assertOwnedId($request, Account::class, $data['account_id']);
        $user = $request->user();
        $cat = new Categorizer($user);
        $validCats = Category::where('user_id', $user->id)->pluck('id')->all();
        $created = 0;
        $skipped = 0;
        $categorized = 0;
        foreach ($data['rows'] as $row) {
            $amount = (float) $row['amount'];
            if (abs($amount) < 0.005) {
                $skipped++;

                continue;
            }
            $date = Carbon::parse($row['date'])->format('Y-m-d');
            $hash = hash('sha256', implode('|', [$data['account_id'], $date, number_format($amount, 2, '.', ''), mb_strtolower(trim($row['description'] ?? ''))]));
            if (Transaction::where('user_id', $user->id)->where('import_hash', $hash)->exists()) {
                $skipped++;

                continue;
            }
            $type = $amount < 0 ? 'expense' : 'income';
            $categoryId = isset($row['category_id']) && in_array((int) $row['category_id'], $validCats, true)
                ? (int) $row['category_id'] : $cat->guess($row['description'] ?? null, $type);
            $categoryId && $categorized++;
            $user->transactions()->create([
                'account_id' => $data['account_id'], 'category_id' => $categoryId, 'type' => $type,
                'amount' => abs($amount), 'date' => $date, 'description' => $row['description'] ?? null,
                'import_hash' => $hash,
            ]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'categorized' => $categorized];
    }

    public function export(Request $request)
    {
        $rows = $this->filtered($request)->with(['category:id,name', 'account:id,name', 'transferAccount:id,name'])
            ->orderBy('date')->get();
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Date', 'Compte', 'Type', 'Catégorie', 'Libellé', 'Montant', 'Vers compte', 'Notes'], ';');
        foreach ($rows as $t) {
            $signed = $t->type === 'expense' ? -$t->amount : $t->amount;
            fputcsv($out, [
                $t->date->format('Y-m-d'), $t->account?->name, $t->type, $t->category?->name,
                $t->description, number_format($signed, 2, ',', ''), $t->transferAccount?->name, $t->notes,
            ], ';');
        }
        rewind($out);
        $csv = stream_get_contents($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="transactions.csv"',
        ]);
    }
}
