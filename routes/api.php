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
