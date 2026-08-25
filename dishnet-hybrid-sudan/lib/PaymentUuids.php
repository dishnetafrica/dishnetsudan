<?php
declare(strict_types=1);

/**
 * PaymentUuids — Canonical UCRM payment method UUID map for DishNet Africa.
 *
 * UCRM requires methodId to be a UUID string (e.g. "6efe0fa8-..."), NOT a
 * slug like "cash" or an integer. These UUIDs are fetched from:
 *   GET /api/v1.0/payment-methods  (crm_debug?action=payment_methods)
 *
 * ── Active methods (shown in UI) ────────────────────────────────────────
 *   Cash          → 6efe0fa8-36b2-4dd1-b049-427bffc7d369  ← default fallback
 *
 * ── Inactive / hidden from UI (still valid for legacy data) ─────────────
 *   Bank Transfer         → 4145b5f5-3bbc-45e3-8fc5-9cda970c62fb
 *   Bank Transfer/Stanbic → aaaaecfe-e46e-478d-b446-eb4b8e0b216c
 *   Bank Transfer/ECO     → c1e5d8bc-7012-4bb7-a9e5-a8364711d1b7
 *   Bank Transfer/Equity  → 0009fe7d-19f3-4b06-82e5-6de15f0a3c97
 *   Check/ECO BANK        → 11721cdf-a498-48be-903e-daa67552e4f6
 *   Check/Stanbic         → 1422caf4-f4a4-4006-a2c8-93aa56635f9f
 *
 * ── Not available (no mobile money) ─────────────────────────────────────
 *   Mobile Money — no UUID exists in this UCRM instance.
 *   All "mobile_money" slugs must fall back to Cash UUID.
 *
 * ── How to resolve ──────────────────────────────────────────────────────
 *   Use PaymentUuids::resolve($value) everywhere a methodId payload key
 *   is built. It handles: UUID pass-through, slug strings, integer IDs,
 *   display names, and case-insensitive matching.
 *
 * PHP 7.4 compatible. Zero dependencies.
 */
class PaymentUuids
{
    // ── UUID constants ───────────────────────────────────────────────────────
    const CASH          = '6efe0fa8-36b2-4dd1-b049-427bffc7d369';
    const BANK_TRANSFER = '4145b5f5-3bbc-45e3-8fc5-9cda970c62fb';
    const BANK_STANBIC  = 'aaaaecfe-e46e-478d-b446-eb4b8e0b216c';
    const BANK_ECO      = 'c1e5d8bc-7012-4bb7-a9e5-a8364711d1b7';
    const BANK_EQUITY   = '0009fe7d-19f3-4b06-82e5-6de15f0a3c97';
    const CHECK_ECO     = '11721cdf-a498-48be-903e-daa67552e4f6';
    const CHECK_STANBIC = '1422caf4-f4a4-4006-a2c8-93aa56635f9f';

    // Default for unknown / mobile_money (no MM method in this UCRM instance)
    const DEFAULT_UUID  = self::CASH;

    // UUID regex pattern (xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx)
    const UUID_PATTERN  = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Resolve any payment method value → valid UCRM UUID.
     *
     * Accepts:
     *   - UUID string already           → pass through unchanged
     *   - Legacy slug: 'cash', 'bank_transfer', 'mobile_money'
     *   - Display name: 'Cash', 'Bank Transfer', 'Mobile Money'
     *   - Legacy integer: 2 (cash), 3 (bank), 6 (mobile)
     *   - null / unknown                → DEFAULT_UUID (Cash)
     *
     * @param  mixed $value
     * @return string UUID
     */
    public static function resolve($value): string
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_UUID;
        }

        // Already a UUID — pass through
        if (is_string($value) && preg_match(self::UUID_PATTERN, $value)) {
            return $value;
        }

        // Integer legacy IDs (UCRM used 2/3/6 in older API versions)
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $intMap = [
                2 => self::CASH,
                3 => self::BANK_TRANSFER,
                6 => self::DEFAULT_UUID,  // mobile_money → Cash (no MM method)
            ];
            return $intMap[(int)$value] ?? self::DEFAULT_UUID;
        }

        // String slug / display name
        $slug = strtolower(trim((string)$value));
        $slug = str_replace([' ', '-'], '_', $slug);

        $slugMap = [
            'cash'          => self::CASH,
            'bank_transfer' => self::BANK_TRANSFER,
            'bank'          => self::BANK_TRANSFER,
            'mobile_money'  => self::DEFAULT_UUID,  // no MM → Cash
            'mobile'        => self::DEFAULT_UUID,
            'check'         => self::CHECK_ECO,
            'cheque'        => self::CHECK_ECO,
            'import'        => self::DEFAULT_UUID,
            'other'         => self::DEFAULT_UUID,
        ];

        return $slugMap[$slug] ?? self::DEFAULT_UUID;
    }

    /**
     * Returns true if $value is already a valid UUID string.
     *
     * @param  mixed $value
     * @return bool
     */
    public static function isUuid($value): bool
    {
        return is_string($value) && (bool)preg_match(self::UUID_PATTERN, $value);
    }

    /**
     * Returns the UI-visible payment method options for DishNet.
     * Mobile Money and Bank accounts are HIDDEN (no MM method; bank rarely used).
     * Only Cash is shown to agents/retailers.
     *
     * @return array  [['value'=>'...uuid...', 'label'=>'Cash'], ...]
     */
    public static function uiOptions(): array
    {
        return [
            ['value' => self::CASH, 'label' => 'Cash'],
        ];
    }
}
