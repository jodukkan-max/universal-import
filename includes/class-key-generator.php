<?php
/**
 * Key Generator — produces cryptographically secure random keys used for the
 * scraper auth key (X-Scraper-Key).
 *
 * This lives in its own file (and is loaded first) so it can be used by the
 * activation hook without relying on the admin page.
 */

class Rsi_Key_Generator {

    /**
     * Generate a cryptographically secure, URL-safe key.
     *
     * Uses WordPress's wp_generate_password() when available (it produces a
     * 32-character alphanumeric token with no special characters, which is
     * safe to paste into headers, JSON, and the extension config). Falls back
     * to random_int() for contexts where pluggable.php hasn't loaded yet.
     *
     * @param  int $length Number of characters to generate.
     * @return string
     */
    public static function generate(int $length = 32): string {
        if (function_exists('wp_generate_password')) {
            return wp_generate_password($length, false, false);
        }
        return self::fallback($length);
    }

    /**
     * Fallback generator for contexts where wp_generate_password() is
     * unavailable (e.g. very early bootstrap).
     *
     * @param  int $length
     * @return string
     */
    private static function fallback(int $length): string {
        $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $key      = '';
        $max      = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) {
            $key .= $alphabet[random_int(0, $max)];
        }
        return $key;
    }
}
