<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function index(Request $request)
    {
        $q = Alert::where('user_id', $request->user()->id);
        if ($request->boolean('unread')) {
            $q->where('is_read', false);
        }

        return $q->latest()->limit(100)->get();
    }

    public function update(Request $request, Alert $alert)
    {
        $this->owned($request, $alert)->update($request->validate(['is_read' => 'required|boolean']));

        return $alert;
    }

    public function readAll(Request $request)
    {
        return ['updated' => Alert::where('user_id', $request->user()->id)->where('is_read', false)->update(['is_read' => true])];
    }

    public function destroy(Request $request, Alert $alert)
    {
        $this->owned($request, $alert)->delete();

        return response()->noContent();
    }
}
