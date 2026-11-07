<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\Category;
use App\Services\ReportService;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    /** Budgets avec consommation du mois (?month=YYYY-MM). */
    public function index(Request $request)
    {
        $month = $request->query('month', now()->format('Y-m'));

        return (new ReportService($request->user()))->budgets($month);
    }

    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'category_id' => "$req|integer",
            'amount' => "$req|numeric|gt:0",
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'rollover' => 'sometimes|boolean',
            'alert_threshold' => 'sometimes|integer|min:1|max:100',
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $this->assertOwnedId($request, Category::class, $data['category_id']);

        return response()->json($request->user()->budgets()->create($data), 201);
    }

    public function show(Request $request, Budget $budget)
    {
        return $this->owned($request, $budget)->load('category');
    }

    public function update(Request $request, Budget $budget)
    {
        $this->owned($request, $budget);
        $data = $request->validate($this->rules(true));
        $this->assertOwnedId($request, Category::class, $data['category_id'] ?? null);
        $budget->update($data);

        return $budget;
    }

    public function destroy(Request $request, Budget $budget)
    {
        $this->owned($request, $budget)->delete();

        return response()->noContent();
    }
}
