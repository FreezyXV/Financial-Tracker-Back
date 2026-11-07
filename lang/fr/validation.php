<?php

return [
    'accepted' => 'Le champ :attribute doit être accepté.',
    'after_or_equal' => 'Le champ :attribute doit être une date postérieure ou égale au :date.',
    'array' => 'Le champ :attribute doit être une liste.',
    'boolean' => 'Le champ :attribute doit être vrai ou faux.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'current_password' => 'Le mot de passe actuel est incorrect.',
    'date' => "Le champ :attribute n'est pas une date valide.",
    'different' => 'Les champs :attribute et :other doivent être différents.',
    'email' => 'Le champ :attribute doit être une adresse email valide.',
    'gt' => ['numeric' => 'Le champ :attribute doit être supérieur à :value.'],
    'in' => 'La valeur du champ :attribute est invalide.',
    'integer' => 'Le champ :attribute doit être un entier.',
    'max' => [
        'numeric' => 'Le champ :attribute ne peut pas dépasser :max.',
        'string' => 'Le champ :attribute ne peut pas dépasser :max caractères.',
        'array' => 'Le champ :attribute ne peut pas contenir plus de :max éléments.',
    ],
    'min' => [
        'numeric' => 'Le champ :attribute doit être au moins :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
        'array' => 'Le champ :attribute doit contenir au moins :min élément(s).',
    ],
    'numeric' => 'Le champ :attribute doit être un nombre.',
    'password' => [
        'min' => 'Le mot de passe doit contenir au moins :min caractères.',
    ],
    'required' => 'Le champ :attribute est obligatoire.',
    'required_if' => 'Le champ :attribute est obligatoire pour ce type d’opération.',
    'required_with' => 'Le champ :attribute est obligatoire.',
    'size' => ['string' => 'Le champ :attribute doit contenir :size caractères.'],
    'string' => 'Le champ :attribute doit être un texte.',
    'unique' => 'Cette valeur de :attribute est déjà utilisée.',

    'attributes' => [
        'name' => 'nom', 'email' => 'email', 'password' => 'mot de passe',
        'current_password' => 'mot de passe actuel', 'amount' => 'montant', 'date' => 'date',
        'account_id' => 'compte', 'transfer_account_id' => 'compte de destination',
        'category_id' => 'catégorie', 'asset_id' => 'actif', 'quantity' => 'quantité',
        'price' => 'prix', 'fees' => 'frais', 'symbol' => 'symbole', 'type' => 'type',
        'description' => 'libellé', 'target_amount' => 'montant cible', 'next_date' => 'prochaine échéance',
        'frequency' => 'fréquence', 'pattern' => 'motif', 'end_date' => 'date de fin', 'start_date' => 'date de début',
    ],
];
