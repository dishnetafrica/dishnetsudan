<?php
declare(strict_types=1);

/**
 * JwtAuth — Zero-dependency JWT for DishNet Hybrid API v2.
 *
 * Uses HMAC-SHA256. Secret is derived from UCRM plugin secret + config salt.
 * Tokens include: sub (retailer_id), role, name, iat, exp.
 *
 * Usage:
 *   $jwt = new JwtAuth($secret, 86400); // 24h tokens
 *   $token = $jwt->issue(['sub' => 42, 'role' => 'engineer', 'name' => 'Diko']);
 *   $claims = $jwt->verify($token); // throws on invalid/expired
 *
 * PHP 7.4 compatible. Zero external dependencies.
 */
class JwtAuth
{
    private string $secret;
    private int $ttl;

    /**
     * @param string $secret  HMAC signing secret (min 32 chars recommended)
     * @param int    $ttl     Token lifetime in seconds (default 86400 = 24h)
     */
    public function __construct(string $secret, int $ttl = 86400)
    {
        if (strlen($secret) < 16) {
            throw new \InvalidArgumentException('JWT secret must be at least 16 characters');
        }
        $this->secret = $secret;
        $this->ttl    = $ttl;
    }

    /**
     * Create a JwtAuth from plugin config.
     * Derives secret from webhook_secret + crm_app_key + a constant salt.
     */
    public static function fromConfig(array $config): self
    {
        $parts = [
            $config['webhook_secret'] ?? '',
            $config['crm_app_key'] ?? $config['crm_auth_token'] ?? '',
            'DishNet-Hybrid-JWT-v2-2026',
        ];
        $secret = hash('sha256', implode('|', $parts));
        $ttl    = (int)($config['jwt_ttl_seconds'] ?? 86400);
        return new self($secret, max(3600, $ttl));
    }

    /**
     * Issue a signed JWT.
     *
     * @param array $claims Must include 'sub' (subject/user ID).
     *                      Automatically adds: iat, exp, jti.
     * @return string Base64url-encoded JWT
     */
    public function issue(array $claims): string
    {
        if (empty($claims['sub'])) {
            throw new \InvalidArgumentException('JWT claims must include "sub"');
        }

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $claims['iat'] = $claims['iat'] ?? time();
        $claims['exp'] = $claims['exp'] ?? (time() + $this->ttl);
        $claims['jti'] = $claims['jti'] ?? bin2hex(random_bytes(12));

        $segments = [
            $this->base64url(json_encode($header)),
            $this->base64url(json_encode($claims)),
        ];
        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $this->secret, true);
        $segments[] = $this->base64url($signature);

        return implode('.', $segments);
    }

    /**
     * Verify and decode a JWT.
     *
     * @param string $token The JWT string
     * @return array Decoded claims
     * @throws \RuntimeException On invalid signature, expired token, or malformed JWT
     */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Malformed JWT: expected 3 segments');
        }

        [$headerB64, $claimsB64, $sigB64] = $parts;

        // Verify signature
        $signingInput = $headerB64 . '.' . $claimsB64;
        $expectedSig  = hash_hmac('sha256', $signingInput, $this->secret, true);
        $actualSig    = $this->base64urlDecode($sigB64);

        if (!hash_equals($expectedSig, $actualSig)) {
            throw new \RuntimeException('Invalid JWT signature');
        }

        // Decode claims
        $claims = json_decode($this->base64urlDecode($claimsB64), true);
        if (!is_array($claims)) {
            throw new \RuntimeException('Malformed JWT claims');
        }

        // Check expiry (5 second leeway for clock skew)
        if (isset($claims['exp']) && $claims['exp'] < (time() - 5)) {
            throw new \RuntimeException('JWT expired');
        }

        return $claims;
    }

    /**
     * Extract claims WITHOUT verifying signature (for logging/debugging only).
     */
    public function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        return json_decode($this->base64urlDecode($parts[1]), true);
    }

    /**
     * Refresh a token (issue new one with same claims, fresh exp).
     */
    public function refresh(string $token): string
    {
        $claims = $this->verify($token); // throws if invalid
        unset($claims['iat'], $claims['exp'], $claims['jti']);
        return $this->issue($claims);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64urlDecode(string $data): string
    {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) + (4 - strlen($data) % 4) % 4, '=');
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64url encoding');
        }
        return $decoded;
    }
}
