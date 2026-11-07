<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        return Category::where('user_id', $request->user()->id)
            ->withCount('transactions')->orderBy('type')->orderBy('name')->get();
    }

    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => "$req|string|max:80",
            'type' => [$req, Rule::in(['income', 'expense'])],
            'color' => 'sometimes|string|max:9',
            'icon' => 'nullable|string|max:40',
            'parent_id' => 'nullable|integer',
            'is_essential' => 'sometimes|boolean',
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $this->assertOwnedId($request, Category::class, $data['parent_id'] ?? null);

        return response()->json($request->user()->categories()->create($data), 201);
    }

    public function show(Request $request, Category $category)
    {
        return $this->owned($request, $category);
    }

    public function update(Request $request, Category $category)
    {
        $this->owned($request, $category);
        $data = $request->validate($this->rules(true));
        $this->assertOwnedId($request, Category::class, $data['parent_id'] ?? null);
        abort_if(($data['parent_id'] ?? null) === $category->id, 422, 'Une catégorie ne peut pas être son propre parent.');
        $category->update($data);

        return $category;
    }

    public function destroy(Request $request, Category $category)
    {
        $this->owned($request, $category)->delete();

        return response()->noContent();
    }
}
