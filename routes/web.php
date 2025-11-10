<?php

use App\Http\Controllers\EndorsementsController;
use App\Http\Controllers\FringeController;
use App\Http\Controllers\YegvoteController;
use Illuminate\Support\Facades\Route;

// Route::statamic('example', 'example-view', [
//    'title' => 'Example'
// ]);
//

Route::redirect('fringe', '/fringe-2025/reviews');
Route::get('/endorsements', fn() => redirect('/yegvote-2025/endorsements'));
Route::get('/fringe-2025/reviews', [ FringeController::class, 'currentYear' ]);
Route::get('/yegvote-2025', [ YegvoteController::class, 'yegvote_2025' ]);
Route::get('/yegvote-2025/endorsements', [ EndorsementsController::class, 'currentYear' ]);
//Route::get('/fringe/{entry}/social', [ FringeController::class, 'generateSocialImage' ]);
