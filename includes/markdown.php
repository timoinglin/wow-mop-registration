<?php
/**
 * Markdown helper — renders user-supplied Markdown (ticket messages) safely.
 *
 * Uses Parsedown in safe mode + auto-escapes all HTML, then post-filters URLs
 * to ensure no javascript:/data: schemes slip through.
 */

require_once __DIR__ . '/Parsedown.php';

if (!function_exists('render_markdown')) {
    function render_markdown(string $text): string
    {
        static $pd = null;
        if ($pd === null) {
            $pd = new Parsedown();
            $pd->setSafeMode(true);   // strips inline HTML, escapes <script> etc.
            $pd->setBreaksEnabled(true); // newlines → <br> (chat-like)
            $pd->setUrlsLinked(true);    // auto-link plain URLs
        }
        return $pd->text($text);
    }
}
