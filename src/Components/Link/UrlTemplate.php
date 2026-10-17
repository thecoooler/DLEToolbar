<?php

namespace iCooLER\DLEToolbar\Components\Link;

final class UrlTemplate
{
    private const VALUE = '~\[xfvalue_([a-z0-9_-]+)\]~i';
    private const GIVEN = '~\[xfgiven_([a-z0-9_-]+)\]((?:(?!\[/?xfgiven_).)*)\[/xfgiven_\1\]~is';

    public static function normalize(string $template): string
    {
        $template = trim($template);
        $plain = self::conditions($template, [], true);
        if ($plain === null) return '';
        /** @var string $candidate */
        $candidate = preg_replace(self::VALUE, 'value', $plain);
        if (stripos($candidate, '[xf') !== false || stripos($candidate, '[/xf') !== false) return '';
        if (preg_match('~^\[xfvalue_[a-z0-9_-]+\]$~i', $plain)) return $template;
        return self::normalizeUrl($candidate) !== '' ? $template : '';
    }

    /** @param array<string, array{value: string, given: bool}> $fields */
    public static function resolve(string $template, bool $isNews, array $fields): string
    {
        $hasTags = preg_match('~\[/?xf(?:value|given)_~i', $template);
        if (!$hasTags) return self::normalizeUrl($template);
        if (!$isNews) return '';
        $plain = self::conditions($template, $fields);
        if ($plain === null) return '';
        $wholeValue = preg_match('~^\[xfvalue_[a-z0-9_-]+\]$~i', $plain);
        $missingValue = false;
        /** @var string $url */
        $url = preg_replace_callback(self::VALUE, static function (array $match) use ($fields, $wholeValue, &$missingValue): string {
            $field = $fields[strtolower($match[1])] ?? null;
            if ($field === null || $field['value'] === '') {
                $missingValue = true;
                return '';
            }
            $value = html_entity_decode($field['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return $wholeValue ? $value : rawurlencode($value);
        }, $plain);
        return $missingValue ? '' : self::normalizeUrl($url);
    }

    /** @param array<string, array{value: string, given: bool}> $fields */
    private static function conditions(string $template, array $fields, bool $validate = false): ?string
    {
        do {
            /** @var string $template */
            $template = preg_replace_callback(self::GIVEN, static fn (array $match): string =>
                $validate || ($fields[strtolower($match[1])]['given'] ?? false) ? $match[2] : '',
                $template, -1, $count);
        } while ($count > 0);
        return preg_match('~\[/?xfgiven_~i', $template) ? null : $template;
    }

    /** Accept HTTP(S) URLs and site-relative paths; reject browser URL ambiguities. */
    private static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return '';
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) return $url;
        return preg_match('~^https?://~i', $url) && filter_var($url, FILTER_VALIDATE_URL) !== false
            ? $url : '';
    }
}
