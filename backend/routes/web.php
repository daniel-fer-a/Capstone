<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => 'CCHIA Postulaciones',
    'stage' => 'Base de backend; interfaz React pendiente de integración.',
]));
