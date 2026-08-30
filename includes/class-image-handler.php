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
        // Strip query parameters for cache key (CDN URLs often have ?v=...).
        $cache_key = $this->cache_key($url);

        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $id = $this->sideload($url, $title);
        $this->cache[$cache_key] = $id;
        return $id;
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
     * Build a cache key from a URL.
     */
    private function cache_key(string $url): string {
        return md5($url);
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
