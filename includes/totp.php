<?php
/**
 * totp.php — RFC 6238 TOTP engine
 * Pure PHP. No external dependencies.
 * Compatible with Google Authenticator, Authy, 1Password, Bitwarden, etc.
 */
class TOTP {
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Generate a cryptographically secure 160-bit Base32 secret */
    public static function generateSecret(): string {
        $bytes = random_bytes(20);
        $out = ''; $buf = 0; $bits = 0;
        foreach (str_split($bytes) as $b) {
            $buf = ($buf << 8) | ord($b); $bits += 8;
            while ($bits >= 5) { $bits -= 5; $out .= self::B32[($buf >> $bits) & 0x1F]; }
        }
        if ($bits > 0) $out .= self::B32[($buf << (5 - $bits)) & 0x1F];
        return $out;
    }

    /** Decode Base32 → raw bytes */
    private static function decode(string $s): string {
        $s = strtoupper(str_replace([' ', '-', '='], '', $s));
        $out = ''; $buf = 0; $bits = 0;
        foreach (str_split($s) as $c) {
            $v = strpos(self::B32, $c);
            if ($v === false) continue;
            $buf = ($buf << 5) | $v; $bits += 5;
            if ($bits >= 8) { $bits -= 8; $out .= chr(($buf >> $bits) & 0xFF); }
        }
        return $out;
    }

    /** Generate TOTP code for a given timestamp (default: now) */
    public static function getCode(string $secret, int $ts = 0, int $digits = 6, int $period = 30): string {
        $counter = (int)floor(($ts ?: time()) / $period);
        $key = self::decode($secret);
        $msg = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $msg, $key, true);
        $off  = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$off])     & 0x7F) << 24) |
            ((ord($hash[$off + 1]) & 0xFF) << 16) |
            ((ord($hash[$off + 2]) & 0xFF) <<  8) |
             (ord($hash[$off + 3]) & 0xFF)
        ) % (10 ** $digits);
        return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a code. Accepts ±1 window (±30s) to handle clock drift.
     * Uses hash_equals to prevent timing attacks.
     */
    public static function verify(string $secret, string $code, int $window = 1): bool {
        $code = trim(str_replace(' ', '', $code));
        if (!preg_match('/^\d{6}$/', $code)) return false;
        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::getCode($secret, $now + $i * 30), $code)) return true;
        }
        return false;
    }

    /** Build an otpauth:// URI for QR scanning */
    public static function getUri(string $secret, string $account, string $issuer = 'EduCore'): string {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer), rawurlencode($account), $secret, rawurlencode($issuer)
        );
    }

    /** QR code image URL via qrserver.com (free, no API key) */
    public static function getQrUrl(string $uri, int $size = 220): string {
        return 'https://api.qrserver.com/v1/create-qr-code/?size='.$size.'x'.$size
            .'&data='.rawurlencode($uri).'&format=png&margin=12';
    }

    /** Human-readable secret in groups of 4 */
    public static function formatSecret(string $s): string {
        return implode(' ', str_split(strtoupper($s), 4));
    }
}
