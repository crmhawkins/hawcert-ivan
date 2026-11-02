<?php

use App\Http\Controllers\Api\AccessValidationController;
use App\Http\Controllers\Api\CertificateValidationController;
use App\Http\Controllers\Api\CredentialApiController;
use App\Http\Controllers\Api\KeyValidationController;
use Illuminate\Support\Facades\Route;

Route::post('/validate-certificate', [CertificateValidationController::class, 'validateCertificate']);
Route::post('/validate-access', [AccessValidationController::class, 'validateAccess']);
Route::post('/validate-key', [KeyValidationController::class, 'validateKey']);
Route::post('/get-credentials', [CredentialApiController::class, 'getCredentials']);
Route::post('/ssh/validate', [\App\Http\Controllers\Api\SshValidationController::class, 'validate']);
Route::post('/admin/register-ssh-server', [\App\Http\Controllers\Api\SshAdminController::class, 'register']);
Route::post('/admin/toggle-certificate', [\App\Http\Controllers\Api\CertificateAdminController::class, 'toggleByEmail']);

// Certificados SSH temporales de un solo uso (SshCertificateController)
Route::get('/ssh/ca', [\App\Http\Controllers\Api\SshCertificateController::class, 'ca']);
Route::post('/ssh/challenge', [\App\Http\Controllers\Api\SshCertificateController::class, 'challenge']);
Route::post('/ssh/issue', [\App\Http\Controllers\Api\SshCertificateController::class, 'issue']);
Route::post('/ssh/consume', [\App\Http\Controllers\Api\SshCertificateController::class, 'consume']);

// Claves de un solo uso con firma del .pem para servicios de tipo «api» (p. ej. correo-api; ver SignedKeyController)
Route::middleware('throttle:60,1')->group(function () {
    Route::post('/signed-key/challenge', [\App\Http\Controllers\Api\SignedKeyController::class, 'challenge']);
    Route::post('/signed-key/issue', [\App\Http\Controllers\Api\SignedKeyController::class, 'issue']);
});
