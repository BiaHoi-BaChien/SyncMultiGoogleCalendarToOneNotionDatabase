<?php

use App\Http\Controllers\Auth\PasskeyController;
use Illuminate\Support\Facades\Route;

Route::prefix('passkeys')->group(function () {
    Route::post('registration/challenge', [PasskeyController::class, 'createRegistrationChallenge']);
    Route::post('registration/finish', [PasskeyController::class, 'finishRegistration']);
    Route::post('login/challenge', [PasskeyController::class, 'createLoginChallenge']);
    Route::post('login/finish', [PasskeyController::class, 'finishLogin']);
});
