<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

abstract class Controller
{
    /** Garantit que la ressource appartient à l'utilisateur connecté (404 sinon). */
    protected function owned(Request $request, Model $model): Model
    {
        abort_if((int) $model->user_id !== (int) $request->user()->id, 404);

        return $model;
    }

    /** Vérifie qu'un id de relation appartient bien à l'utilisateur. */
    protected function assertOwnedId(Request $request, string $modelClass, ?int $id): void
    {
        if ($id === null) {
            return;
        }
        abort_unless($modelClass::where('id', $id)->where('user_id', $request->user()->id)->exists(), 422, 'Ressource liée invalide.');
    }
}
