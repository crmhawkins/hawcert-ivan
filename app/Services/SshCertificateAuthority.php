<?php

namespace App\Services;

use App\Models\SshCaKey;
use Illuminate\Support\Facades\Crypt;

/**
 * Autoridad certificadora SSH de HawCert.
 *
 * Firma claves públicas ed25519 efímeras produciendo certificados OpenSSH
 * (formato ssh-ed25519-cert-v01@openssh.com, ver PROTOCOL.certkeys) sin
 * depender del binario ssh-keygen. La clave de la CA se guarda cifrada en BD
 * (tabla ssh_ca_keys) para sobrevivir a redeploys del contenedor.
 */
class SshCertificateAuthority
{
    public const CERT_TYPE = 'ssh-ed25519-cert-v01@openssh.com';
    public const KEY_TYPE  = 'ssh-ed25519';
    public const CA_NAME   = 'hawcert-ssh-ca';

    /** Obtiene (o crea la primera vez) la clave de la CA. */
    public function getCaKey(): SshCaKey
    {
        $ca = SshCaKey::where('name', self::CA_NAME)->where('is_active', true)->first();
        if ($ca) {
            return $ca;
        }

        $pair = sodium_crypto_sign_keypair();
        $pk   = sodium_crypto_sign_publickey($pair);
        $sk   = sodium_crypto_sign_secretkey($pair);

        return SshCaKey::create([
            'name'        => self::CA_NAME,
            'public_key'  => $this->encodePublicKeyLine($pk, self::CA_NAME),
            'private_key' => Crypt::encryptString(base64_encode($sk)),
            'is_active'   => true,
        ]);
    }

    /** Línea OpenSSH de la clave pública de la CA (para TrustedUserCAKeys). */
    public function caPublicKeyLine(): string
    {
        return $this->getCaKey()->public_key;
    }

    /**
     * Parsea una línea "ssh-ed25519 AAAA... comentario" y devuelve los 32 bytes de la clave.
     * Lanza InvalidArgumentException si no es una clave ed25519 válida.
     */
    public function parseEd25519PublicKey(string $line): string
    {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 2 || $parts[0] !== self::KEY_TYPE) {
            throw new \InvalidArgumentException('Solo se admiten claves públicas ssh-ed25519.');
        }
        $blob = base64_decode($parts[1], true);
        if ($blob === false) {
            throw new \InvalidArgumentException('Clave pública mal codificada.');
        }
        $off  = 0;
        $type = $this->readString($blob, $off);
        $pk   = $this->readString($blob, $off);
        if ($type !== self::KEY_TYPE || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \InvalidArgumentException('Clave pública ed25519 inválida.');
        }
        return $pk;
    }

    /** Devuelve "ssh-ed25519 <base64>" canónico (sin comentario) de una línea de clave pública. */
    public function canonicalPublicKeyLine(string $line): string
    {
        $pk = $this->parseEd25519PublicKey($line);
        return self::KEY_TYPE . ' ' . base64_encode($this->packString(self::KEY_TYPE) . $this->packString($pk));
    }

    /**
     * Firma una clave pública efímera y devuelve la línea del certificado OpenSSH.
     *
     * @param string   $userPublicKeyLine  "ssh-ed25519 AAAA..." del cliente
     * @param int      $serial             serie única (uint64)
     * @param string   $keyId              identificador legible (aparece en los logs de sshd)
     * @param string[] $principals         usuarios del sistema permitidos
     * @param int      $validAfter         unix timestamp
     * @param int      $validBefore        unix timestamp
     * @param string[] $extensions         p.ej. ['permit-pty', 'permit-port-forwarding']
     */
    public function signUserCertificate(
        string $userPublicKeyLine,
        int $serial,
        string $keyId,
        array $principals,
        int $validAfter,
        int $validBefore,
        array $extensions = ['permit-pty']
    ): string {
        $userPk = $this->parseEd25519PublicKey($userPublicKeyLine);
        $ca     = $this->getCaKey();
        $caSk   = base64_decode(Crypt::decryptString($ca->private_key), true);
        if ($caSk === false || strlen($caSk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('Clave privada de la CA SSH corrupta.');
        }
        $caPk = sodium_crypto_sign_publickey_from_secretkey($caSk);

        sort($extensions, SORT_STRING); // OpenSSH exige orden lexicográfico
        $packedPrincipals = '';
        foreach ($principals as $p) {
            $packedPrincipals .= $this->packString($p);
        }
        $packedExtensions = '';
        foreach ($extensions as $ext) {
            $packedExtensions .= $this->packString($ext) . $this->packString('');
        }
        $caKeyBlob = $this->packString(self::KEY_TYPE) . $this->packString($caPk);

        $body = $this->packString(self::CERT_TYPE)
            . $this->packString(random_bytes(32))          // nonce
            . $this->packString($userPk)                   // clave pública del cliente
            . $this->packUint64($serial)
            . $this->packUint32(1)                         // SSH_CERT_TYPE_USER
            . $this->packString($keyId)
            . $this->packString($packedPrincipals)
            . $this->packUint64($validAfter)
            . $this->packUint64($validBefore)
            . $this->packString('')                        // critical options (ninguna)
            . $this->packString($packedExtensions)
            . $this->packString('')                        // reserved
            . $this->packString($caKeyBlob);               // signature key

        $signature = sodium_crypto_sign_detached($body, $caSk);
        $sigBlob   = $this->packString(self::KEY_TYPE) . $this->packString($signature);
        $cert      = $body . $this->packString($sigBlob);

        sodium_memzero($caSk);

        return self::CERT_TYPE . ' ' . base64_encode($cert) . ' ' . $keyId;
    }

    // ── helpers de codificación SSH (RFC 4251) ───────────────────────────────

    private function encodePublicKeyLine(string $pk, string $comment): string
    {
        return self::KEY_TYPE . ' ' . base64_encode($this->packString(self::KEY_TYPE) . $this->packString($pk)) . ' ' . $comment;
    }

    private function packString(string $s): string
    {
        return pack('N', strlen($s)) . $s;
    }

    private function packUint32(int $n): string
    {
        return pack('N', $n);
    }

    private function packUint64(int $n): string
    {
        return pack('J', $n);
    }

    private function readString(string $blob, int &$off): string
    {
        if ($off + 4 > strlen($blob)) {
            throw new \InvalidArgumentException('Blob SSH truncado.');
        }
        $len = unpack('N', substr($blob, $off, 4))[1];
        $off += 4;
        if ($off + $len > strlen($blob)) {
            throw new \InvalidArgumentException('Blob SSH truncado.');
        }
        $s = substr($blob, $off, $len);
        $off += $len;
        return $s;
    }
}
