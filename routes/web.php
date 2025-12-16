<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $record = DB::table('names')->first();

    return response()->json([
        'name' => $record?->name ?? null,
    ]);
});
