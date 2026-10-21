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

// Certificados SSH temporales de un solo uso (ver app/Http/Controllers/Api/SshCertificateController.php)
Route::get('/ssh/ca', [\App\Http\Controllers\Api\SshCertificateController::class, 'ca']);
Route::post('/ssh/challenge', [\App\Http\Controllers\Api\SshCertificateController::class, 'challenge']);
Route::post('/ssh/issue', [\App\Http\Controllers\Api\SshCertificateController::class, 'issue']);
Route::post('/ssh/consume', [\App\Http\Controllers\Api\SshCertificateController::class, 'consume']);
