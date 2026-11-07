<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategorizationRule;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\Categorizer;
use Illuminate\Http\Request;

class RuleController extends Controller
{
    public function index(Request $request)
    {
        return CategorizationRule::where('user_id', $request->user()->id)
            ->with('category:id,name,color,type')->orderBy('priority')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'pattern' => 'required|string|max:500',
            'category_id' => 'required|integer',
            'priority' => 'sometimes|integer|min:0|max:10000',
        ]);
        $this->assertOwnedId($request, Category::class, $data['category_id']);

        return response()->json($request->user()->rules()->create($data)->load('category:id,name,color,type'), 201);
    }

    public function update(Request $request, CategorizationRule $rule)
    {
        $this->owned($request, $rule);
        $data = $request->validate([
            'pattern' => 'sometimes|string|max:500',
            'category_id' => 'sometimes|integer',
            'priority' => 'sometimes|integer|min:0|max:10000',
        ]);
        $this->assertOwnedId($request, Category::class, $data['category_id'] ?? null);
        $rule->update($data);

        return $rule->load('category:id,name,color,type');
    }

    public function destroy(Request $request, CategorizationRule $rule)
    {
        $this->owned($request, $rule)->delete();

        return response()->noContent();
    }

    /** Applique les règles aux transactions non catégorisées. */
    public function apply(Request $request)
    {
        $cat = new Categorizer($request->user());
        $n = 0;
        Transaction::where('user_id', $request->user()->id)->whereNull('category_id')
            ->where('type', '!=', 'transfer')->each(function (Transaction $t) use ($cat, &$n) {
                if ($id = $cat->guess($t->description, $t->type)) {
                    $t->update(['category_id' => $id]);
                    $n++;
                }
            });

        return ['categorized' => $n];
    }
}
