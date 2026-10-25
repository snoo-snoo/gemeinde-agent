<?php

use Illuminate\Support\Facades\Route;

$spa = function () {
    $index = public_path('index.html');
    abort_unless(is_file($index), 503, 'Frontend-Build fehlt.');

    return response()->file($index, ['Content-Type' => 'text/html; charset=UTF-8']);
};

Route::get('/', $spa);
Route::get('/{path}', $spa)->where('path', '^(?!api(?:/|$)|up$).*');
