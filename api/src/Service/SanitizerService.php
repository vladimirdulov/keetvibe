<?php

namespace App\Service;

class SanitizerService
{
    /**
     * Escape HTML entities to prevent XSS
     */
    public function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Strip all HTML tags
     */
    public function strip(string $value): string
    {
        return strip_tags($value);
    }

    /**
     * Escape content for safe display
     */
    public function escapeContent(string $content): string
    {
        // First strip tags, then escape
        return $this->escape($this->strip($content));
    }
}