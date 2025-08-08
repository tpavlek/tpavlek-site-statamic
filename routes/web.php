<?php

use App\Http\Controllers\FringeController;
use Illuminate\Support\Facades\Route;

// Route::statamic('example', 'example-view', [
//    'title' => 'Example'
// ]);
//

Route::get("test", fn() => dd("test"));

Route::get('/fringe-2025/reviews', [ FringeController::class, 'currentYear' ]);
