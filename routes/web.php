<?php

use App\Http\Controllers\FringeController;
use Illuminate\Support\Facades\Route;

// Route::statamic('example', 'example-view', [
//    'title' => 'Example'
// ]);
//

Route::redirect('fringe', '/fringe-2025/reviews');
Route::get('/fringe-2025/reviews', [ FringeController::class, 'currentYear' ]);
//Route::get('/fringe/{entry}/social', [ FringeController::class, 'generateSocialImage' ]);
