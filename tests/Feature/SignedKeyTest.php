<?php

/*
 * Claves firmadas para servicios de tipo «api» (correo-api) y su consumo en /api/validate-key.
 * En Windows, OpenSSL de PHP necesita OPENSSL_CONF apuntando a un openssl.cnf para generar claves.
 */

use App\Http\Controllers\Api\SignedKeyController;
use App\Models\AccessKey;
use App\Models\Certificate;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const CORREO_API_URL = 'https://correos.hawkins.es';

function sk_opensslOptions(): array
{
    $options = ['digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    if ($conf = getenv('OPENSSL_CONF')) {
        $options['config'] = $conf;
    }
    return $options;
}

/** @return array{0:string,1:string} [x509 PEM, clave privada PEM] */
function sk_makeX509(string $cn): array
{
    $key  = openssl_pkey_new(sk_opensslOptions());
    $csr  = openssl_csr_new(['commonName' => $cn, 'emailAddress' => Str::slug($cn) . '@hawkins.test'], $key, sk_opensslOptions());
    $x509 = openssl_csr_sign($csr, null, $key, 365, sk_opensslOptions(), random_int(1, PHP_INT_MAX));
    openssl_x509_export($x509, $certPem);
    openssl_pkey_export($key, $keyPem, null, sk_opensslOptions());
    return [$certPem, $keyPem];
}

/** @return array{0:Certificate,1:string,2:string} */
function sk_makeCertificate(string $cn, array $services = ['correo-api'], array $attrs = []): array
{
    [$certPem, $keyPem] = sk_makeX509($cn);
    $user = User::factory()->create();
    $cert = Certificate::create(array_merge([
        'user_id'          => $user->id,
        'certificate_key'  => Str::random(40),
        'x509_certificate' => $certPem,
        'common_name'      => $cn,
        'email'            => Str::slug($cn) . '@hawkins.test',
        'name'             => $cn,
        'valid_from'       => now()->subDay(),
        'valid_until'      => now()->addYear(),
        'never_expires'    => false,
        'is_active'        => true,
    ], $attrs));
    $ids = Service::whereIn('slug', $services)->pluck('id')->all();
    $cert->services()->sync($ids);
    return [$cert, $certPem, $keyPem];
}

function sk_sign(string $keyPem, string $nonce, string $slug): string
{
    openssl_sign(SignedKeyController::signedPayload($nonce, $slug), $sig, $keyPem, OPENSSL_ALGO_SHA256);
    return base64_encode($sig);
}

function sk_nonce($test): string
{
    return $test->postJson('/api/signed-key/challenge')->assertOk()->json('nonce');
}

/** Pide una key firmada y devuelve la respuesta. */
function sk_issue($test, string $certPem, string $keyPem, string $slug = 'correo-api', ?string $signWithKey = null)
{
    $nonce = sk_nonce($test);
    return $test->postJson('/api/signed-key/issue', [
        'certificate'  => $certPem,
        'service_slug' => $slug,
        'nonce'        => $nonce,
        'signature'    => sk_sign($signWithKey ?? $keyPem, $nonce, $slug),
    ]);
}

it('crea el servicio correo-api de tipo api con la migración', function () {
    $service = Service::where('slug', 'correo-api')->first();
    expect($service)->not->toBeNull()
        ->and($service->service_type)->toBe('api')
        ->and($service->isApi())->toBeTrue()
        ->and($service->endpoint)->toBe(CORREO_API_URL)
        ->and($service->certificates()->count())->toBe(0); // nadie lo tiene hasta que se asigne
});

it('emite una key de un solo uso con firma válida y el servicio la consume una sola vez', function () {
    [$cert, $certPem, $keyPem] = sk_makeCertificate('Ivan Test');

    $resp = sk_issue($this, $certPem, $keyPem)->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('single_use', true)
        ->assertJsonPath('service.slug', 'correo-api');
    $key = $resp->json('access_key');
    expect($key)->toStartWith('ak_')->and(strlen($key))->toBe(51);

    $row = AccessKey::where('key', $key)->first();
    expect($row->service_slug)->toBe('correo-api')
        ->and($row->target_url)->toBe(CORREO_API_URL)
        ->and($row->metadata['issued_via'])->toBe('signed-key')
        ->and($row->expires_at->lte(now()->addSeconds(SignedKeyController::KEY_TTL_SECONDS + 1)))->toBeTrue();

    $this->postJson('/api/validate-key', ['key' => $key, 'url' => CORREO_API_URL . '/api/correo-api/buzones'])
        ->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('service.slug', 'correo-api')
        ->assertJsonPath('certificate.id', $cert->id);

    // Segundo uso: rechazado
    $this->postJson('/api/validate-key', ['key' => $key, 'url' => CORREO_API_URL])
        ->assertStatus(403);
});

it('rechaza una firma hecha con otra clave privada', function () {
    [, $certPem] = sk_makeCertificate('Ivan Test');
    [, $otherKey] = sk_makeX509('Intruso');

    sk_issue($this, $certPem, $otherKey)->assertStatus(403)->assertJsonPath('success', false);
    expect(AccessKey::count())->toBe(0);
});

it('rechaza una firma de otro servicio (no se puede reutilizar para correo-api)', function () {
    [, $certPem, $keyPem] = sk_makeCertificate('Ivan Test');
    $nonce = sk_nonce($this);
    $this->postJson('/api/signed-key/issue', [
        'certificate'  => $certPem,
        'service_slug' => 'correo-api',
        'nonce'        => $nonce,
        'signature'    => sk_sign($keyPem, $nonce, 'otro-servicio'),
    ])->assertStatus(403);
});

it('el nonce es de un solo uso', function () {
    [, $certPem, $keyPem] = sk_makeCertificate('Ivan Test');
    $nonce = sk_nonce($this);
    $body  = ['certificate' => $certPem, 'service_slug' => 'correo-api', 'nonce' => $nonce, 'signature' => sk_sign($keyPem, $nonce, 'correo-api')];

    $this->postJson('/api/signed-key/issue', $body)->assertOk();
    $this->postJson('/api/signed-key/issue', $body)->assertStatus(400);
});

it('rechaza un nonce inventado o caducado', function () {
    [, $certPem, $keyPem] = sk_makeCertificate('Ivan Test');
    $nonce = str_repeat('a', 64);
    $this->postJson('/api/signed-key/issue', [
        'certificate' => $certPem, 'service_slug' => 'correo-api', 'nonce' => $nonce, 'signature' => sk_sign($keyPem, $nonce, 'correo-api'),
    ])->assertStatus(400);

    $nonce = sk_nonce($this);
    $this->travel(SignedKeyController::NONCE_TTL_SECONDS + 5)->seconds();
    $this->postJson('/api/signed-key/issue', [
        'certificate' => $certPem, 'service_slug' => 'correo-api', 'nonce' => $nonce, 'signature' => sk_sign($keyPem, $nonce, 'correo-api'),
    ])->assertStatus(400);
});

it('rechaza un certificado que no tiene el permiso correo-api', function () {
    [, $certPem, $keyPem] = sk_makeCertificate('Empleado', ['externo']);
    sk_issue($this, $certPem, $keyPem)->assertStatus(403)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'no tiene permiso'));
});

it('rechaza un certificado desconocido, desactivado o caducado', function () {
    [$certPem, $keyPem] = sk_makeX509('Desconocido');
    sk_issue($this, $certPem, $keyPem)->assertStatus(404);

    [, $certPem, $keyPem] = sk_makeCertificate('Desactivado', ['correo-api'], ['is_active' => false]);
    sk_issue($this, $certPem, $keyPem)->assertStatus(403);

    [, $certPem, $keyPem] = sk_makeCertificate('Caducado', ['correo-api'], ['valid_until' => now()->subDay()]);
    sk_issue($this, $certPem, $keyPem)->assertStatus(403);
});

it('no emite keys firmadas para servicios que no son de tipo api', function () {
    Service::create(['name' => 'Coolify', 'slug' => 'coolify-externo', 'endpoint' => 'https://externo.hawkins.es', 'service_type' => 'web', 'is_active' => true]);
    [, $certPem, $keyPem] = sk_makeCertificate('Ivan Test', ['correo-api', 'coolify-externo']);
    sk_issue($this, $certPem, $keyPem, 'coolify-externo')->assertStatus(404);
});

it('validate-access (solo certificado público, sin firma) no emite keys para correo-api', function () {
    [, $certPem] = sk_makeCertificate('Ivan Test');
    $this->postJson('/api/validate-access', ['certificate' => $certPem, 'url' => CORREO_API_URL, 'service_slug' => 'correo-api'])
        ->assertStatus(403);
    // ni infiriendo el servicio desde la URL
    $this->postJson('/api/validate-access', ['certificate' => $certPem, 'url' => CORREO_API_URL])
        ->assertStatus(403);
    expect(AccessKey::count())->toBe(0);
});

it('validate-key rechaza la key caducada, la de otro host y la de un certificado al que se le quitó el permiso', function () {
    [$cert, $certPem, $keyPem] = sk_makeCertificate('Ivan Test');

    $k1 = sk_issue($this, $certPem, $keyPem)->json('access_key');
    $this->postJson('/api/validate-key', ['key' => $k1, 'url' => 'https://otro.hawkins.es'])->assertStatus(403);

    $k2 = sk_issue($this, $certPem, $keyPem)->json('access_key');
    $cert->services()->detach();
    $this->postJson('/api/validate-key', ['key' => $k2, 'url' => CORREO_API_URL])
        ->assertStatus(403)->assertJsonPath('message', 'El certificado ya no tiene permiso para este servicio');

    $cert->services()->sync(Service::where('slug', 'correo-api')->pluck('id'));
    $k3 = sk_issue($this, $certPem, $keyPem)->json('access_key');
    $this->travel(SignedKeyController::KEY_TTL_SECONDS + 5)->seconds();
    $this->postJson('/api/validate-key', ['key' => $k3, 'url' => CORREO_API_URL])->assertStatus(403);
});

it('validate-key rechaza keys de servicios api que no vienen del flujo firmado', function () {
    [$cert] = sk_makeCertificate('Ivan Test');
    $key = 'ak_' . Str::random(48);
    AccessKey::create([
        'certificate_id' => $cert->id, 'key' => $key, 'service_slug' => 'correo-api', 'target_url' => CORREO_API_URL,
        'expires_at' => now()->addMinute(), 'is_used' => false, 'metadata' => ['generated_at' => now()->toIso8601String()],
    ]);
    $this->postJson('/api/validate-key', ['key' => $key, 'url' => CORREO_API_URL])->assertStatus(403);
});

it('limita las keys emitidas por minuto y certificado', function () {
    [$cert, $certPem, $keyPem] = sk_makeCertificate('Ivan Test');
    for ($i = 0; $i < SignedKeyController::MAX_KEYS_PER_MINUTE; $i++) {
        AccessKey::create([
            'certificate_id' => $cert->id, 'key' => 'ak_' . Str::random(48), 'service_slug' => 'correo-api',
            'target_url' => CORREO_API_URL, 'expires_at' => now()->addMinute(), 'is_used' => true,
        ]);
    }
    sk_issue($this, $certPem, $keyPem)->assertStatus(429);
});
