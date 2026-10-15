<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Links that open a device in the installed RustDesk client.
 *
 * The ID goes in the path. `rustdesk://<id>` puts it in the host part, which
 * the client lowercases, so a mixed-case custom ID came out "ID not found"
 * (issue #105). The client registers its app name, lowercased, as the scheme,
 * so a renamed rdgen build needs its own: Settings -> Server sets it.
 */
class ClientLink
{
    public const SETTING = 'client_url_scheme';

    public const DEFAULT = 'rustdesk';

    public static function to(?string $id): string
    {
        return self::scheme().'://connection/new/'.rawurlencode((string) $id);
    }

    public static function scheme(): string
    {
        // Device lists build a link per row; read the setting once.
        return once(fn () => self::normalize(
            (string) Setting::get(self::SETTING, (string) config('cortendesk.client_url_scheme'))
        ) ?: self::DEFAULT);
    }

    /** "Level1-Tech-Support://" -> "level1-tech-support"; '' when it can't be a scheme. */
    public static function normalize(string $value): string
    {
        $scheme = strtolower(trim(explode(':', trim($value), 2)[0]));

        return preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) === 1 ? $scheme : '';
    }
}
