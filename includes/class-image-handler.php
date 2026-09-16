<?php
/**
 * Image handler — downloads images from remote URLs and creates WordPress
 * media attachments.
 *
 * Uses WordPress HTTP API (wp_remote_get) for downloading and the media
 * sideload functions for attachment creation.
 */

class Rsi_Image_Handler {

    /**
     * Maximum time (seconds) to spend downloading a single image.
     */
    private const DOWNLOAD_TIMEOUT = 30;

    /**
     * Cache of already-downloaded URLs → attachment IDs to avoid re-downloading
     * the same image within a single import session.
     *
     * @var array<string, int>
     */
    private array $cache = [];

    /**
     * Download an image from a remote URL, insert it as a WordPress attachment,
     * and return the attachment ID.
     *
     * @param string $url       Full image URL.
     * @param string $title     Optional title for the attachment (product name).
     * @return int              Attachment ID, or 0 on failure.
     */
    public function download(string $url, string $title = ''): int {
        return $this->resolve($url, $title);
    }

    /**
     * Resolve an image URL to an attachment ID, reusing an existing attachment
     * when the same source image was already imported (this session OR a past
     * session). This is the dedupe layer that stops the same photo being
     * downloaded/installed multiple times when it appears in the parent gallery
     * AND in one or more variations.
     *
     * @param string $url       Full image URL.
     * @param string $title     Optional title for the attachment (product name).
     * @return int              Attachment ID, or 0 on failure.
     */
    public function resolve(string $url, string $title = ''): int {
        $clean = $this->clean_url($url);
        if ($clean === '') {
            return 0;
        }

        $key = md5($clean);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        // Cross-session dedupe: reuse an attachment previously downloaded from
        // the same source URL (tracked in `_source_url` post meta).
        $existing = $this->find_existing_by_source_url($clean);
        if ($existing > 0) {
            $this->cache[$key] = $existing;
            return $existing;
        }

        $id = $this->sideload($clean, $title);
        $this->cache[$key] = $id;
        return $id;
    }

    /**
     * Resolve multiple image URLs to attachment IDs, preserving order and
     * deduplicating so the SAME image never appears twice in the result.
     *
     * Returns only the IDs of successfully resolved images.
     *
     * @param string[] $urls
     * @param string   $title
     * @return int[]
     */
    public function resolve_many(array $urls, string $title = ''): array {
        $ids  = [];
        $seen = [];
        foreach ($urls as $url) {
            $clean = $this->clean_url($url);
            if ($clean === '') {
                continue;
            }
            $id = $this->resolve($clean, $title);
            if ($id > 0 && !isset($seen[$id])) {
                $seen[$id] = true;
                $ids[]    = $id;
            }
        }
        return $ids;
    }

    /**
     * Download multiple image URLs and return their attachment IDs.
     *
     * Returns only the IDs of successfully downloaded images.
     *
     * @param string[] $urls
     * @param string   $title
     * @return int[]
     */
    public function download_many(array $urls, string $title = ''): array {
        $ids = [];
        foreach ($urls as $url) {
            $id = $this->download($url, $title);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Normalize a URL for cache-key / dedupe purposes: make protocol-relative
     * absolute and strip query parameters (CDN cache-busters like ?v=...), so
     * the same photo served with a different query string reuses one attachment.
     */
    private function clean_url(string $url): string {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }
        $url = preg_replace('/\?.*$/', '', $url);
        return $url;
    }

    /**
     * Find an existing attachment previously downloaded from the same source
     * URL. Uses an in-memory memo so repeated lookups within one import are
     * cheap.
     */
    private function find_existing_by_source_url(string $clean): int {
        static $memo = [];
        if (array_key_exists($clean, $memo)) {
            return $memo[$clean];
        }

        $found = 0;
        $query = new \WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => '_source_url',
            'meta_value'     => $clean,
        ]);
        if (!empty($query->posts)) {
            $found = (int) $query->posts[0];
        }

        $memo[$clean] = $found;
        return $found;
    }

    /**
     * Side-load an image — download and copy directly into the uploads
     * folder WITHOUT going through wp_handle_sideload (which fires other plugins'
     * hooks that may re-crop/resize the file).
     *
     * @param string $url
     * @param string $title
     * @return int
     */
    private function sideload(string $url, string $title = ''): int {
        // Validate URL.
        $url = esc_url_raw($url);
        if (empty($url)) {
            return 0;
        }

        // Require media handling functions.
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Download the file to a temp location with a browser-like User-Agent
        // (some CDNs like Carrefour's Akamai reject non-browser requests).
        add_filter('http_headers_useragent', [$this, 'browser_user_agent']);
        $tmp = download_url($url, self::DOWNLOAD_TIMEOUT);
        remove_filter('http_headers_useragent', [$this, 'browser_user_agent']);
        if (is_wp_error($tmp)) {
            return 0;
        }

        // Determine the filename from the URL.
        $parsed_url = wp_parse_url($url);
        $path       = $parsed_url['path'] ?? '';
        $basename   = basename($path);
        // If no extension, try to detect from mime type.
        if (!preg_match('/\.(jpg|jpeg|png|gif|webp|svg)/i', $basename)) {
            $mime      = wp_check_filetype($basename);
            $ext       = $mime['ext'] ?? 'jpg';
            $basename .= '.' . $ext;
        }

        // Determine mime type from the temp file.
        $mime_type = wp_check_filetype($basename)['type'] ?? mime_content_type($tmp);
        if (!$mime_type) {
            $mime_type = 'image/jpeg';
        }

        // Unique filename in the uploads directory.
        $upload_dir = wp_upload_dir();
        $filename   = wp_unique_filename($upload_dir['path'], $basename);
        $dest       = $upload_dir['path'] . '/' . $filename;

        // Copy temp file directly into uploads — bypass wp_handle_sideload entirely.
        if (!@copy($tmp, $dest)) {
            @unlink($tmp);
            return 0;
        }
        @unlink($tmp);

        // Verify the file has readable dimensions.
        $check = @getimagesize($dest);
        if (!$check) {
            @unlink($dest);
            return 0;
        }

        $attach_url = $upload_dir['url'] . '/' . $filename;

        // Insert attachment into the media library.
        $attachment = [
            'guid'           => $attach_url,
            'post_mime_type' => $mime_type,
            'post_title'     => $title ?: sanitize_file_name(pathinfo($basename, PATHINFO_FILENAME)),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attach_id = wp_insert_attachment($attachment, $dest);

        if (is_wp_error($attach_id) || $attach_id === 0) {
            @unlink($dest);
            return 0;
        }

        // Generate attachment metadata and thumbnails.
        $attach_data = wp_generate_attachment_metadata($attach_id, $dest);
        wp_update_attachment_metadata($attach_id, $attach_data);

        // Remember the source URL so future imports of the same product reuse
        // this attachment instead of downloading a duplicate file.
        update_post_meta($attach_id, '_source_url', $this->clean_url($url));

        return $attach_id;
    }

    /**
     * Return a browser-like User-Agent string for image downloads.
     *
     * Some CDNs (e.g. Carrefour's Akamai) reject requests with a
     * WordPress/PHP User-Agent. This filter mimics a Chrome browser.
     *
     * @param string $ua Original User-Agent.
     * @return string
     */
    public function browser_user_agent(string $ua): string {
        return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    }
}
