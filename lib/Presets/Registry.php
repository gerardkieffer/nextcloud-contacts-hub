<?php

declare(strict_types=1);

namespace OCA\ContactHub\Presets;

final class Registry
{
    /** @return array<string, Preset> */
    public static function all(): array
    {
        return [
            'icloud' => new Preset(
                key: 'icloud',
                label: 'iCloud',
                defaultBaseUrl: 'https://contacts.icloud.com/',
                defaultGroupStrategy: 'passthrough',
                authHint: 'Use an app-specific password generated at appleid.apple.com '
                    . '-> Sign-In and Security -> App-Specific Passwords. Never your main Apple ID password.',
                quirks: [
                    'Exactly one flat address-book collection per account -- no server-side sub-folders.',
                    'Discovery redirects to a per-account host (e.g. p123-contacts.icloud.com) on the '
                        . 'first request -- followed automatically.',
                    'Contact photos are commonly stored as a PHOTO;VALUE=uri reference to an asset URL '
                        . 'rather than inline data -- resolved and re-embedded automatically when syncing.',
                    'Groups use the Apple X-ADDRESSBOOKSERVER-KIND/-MEMBER convention: the group is its '
                        . "own vCard listing its members' UIDs -- it is a separate record, not a property "
                        . 'on the member vCards.',
                    "Contacts.app's own vCard *export* fakes a CATEGORIES property from group "
                        . "membership for interop with non-Apple software, but that's an export-only "
                        . 'artifact -- CardDAV itself never returns CATEGORIES for group membership, only '
                        . 'the separate group vCard. This app reads the real group vCards directly, so it '
                        . "is not affected by that distinction, but it explains why a contact's raw vCard "
                        . 'looks different exported from Contacts.app than fetched over CardDAV.',
                ],
            ),
            'infomaniak' => new Preset(
                key: 'infomaniak',
                label: 'Infomaniak',
                defaultBaseUrl: 'https://sync.infomaniak.com/',
                defaultGroupStrategy: 'passthrough',
                authHint: 'Use the short login on its own (e.g. AB12345), not the full '
                    . 'XXXXXXXX@sync.infomaniak.com form: only the short login authenticates against this '
                    . 'app. A dedicated application password if your account has 2FA enabled.',
                quirks: [
                    'SabreDAV backend (same engine family as Nextcloud/ownCloud/Baikal).',
                    'Infomaniak documents the full XXXXXXXX@sync.infomaniak.com username form, and other '
                        . 'clients do accept it, but it is rejected here -- use the short login.',
                    'current-user-privilege-set nests privilege names two levels deep -- handled '
                        . 'correctly by this app.',
                    'Accounts may expose more than one address-book collection (e.g. a personal one '
                        . 'plus a read-only shared/org directory) -- pick yours explicitly by name.',
                    'Confirmed to preserve Apple-style group vCards byte-for-byte (passthrough).',
                ],
            ),
            'mailo' => new Preset(
                key: 'mailo',
                label: 'Mailo',
                defaultBaseUrl: 'https://carddav.mailo.com',
                defaultGroupStrategy: 'categories',
                authHint: 'Username is your full Mailo e-mail address (e.g. jane.doe@mailo.com), '
                    . 'password is your normal Mailo login password.',
                quirks: [
                    'If discovery against the plain https://carddav.mailo.com URL fails, this app '
                        . 'automatically retries the per-account URL '
                        . 'https://carddav.mailo.com/adbook/{username}/1 before giving up -- Mailo '
                        . "doesn't always support root/well-known discovery.",
                ],
                urlFallbackPattern: 'https://carddav.mailo.com/adbook/{username}/1',
            ),
            'google' => new Preset(
                key: 'google',
                label: 'Google Contacts',
                defaultBaseUrl: 'https://www.googleapis.com/.well-known/carddav',
                // Google does not expose Contacts labels over CardDAV in any
                // form, so there is no group representation to pass through.
                // Per-contact CATEGORIES is what other clients settle on.
                defaultGroupStrategy: 'categories',
                authHint: 'Google requires OAuth 2.0 for CardDAV. There is no username and password that '
                    . 'works here: normal passwords stopped working on 14 March 2025, and app passwords '
                    . 'are not accepted for CardDAV either. This app authenticates with HTTP Basic only, '
                    . 'so it cannot connect to Google Contacts yet.',
                quirks: [
                    'Requires OAuth 2.0. Google\'s own documentation states that any attempt to connect '
                        . 'with Basic authentication returns 401 Unauthorized, so this is a hard refusal '
                        . 'by the server rather than a configuration mistake.',
                    'vCard 3.0 only.',
                    'Contacts labels are NOT exposed over CardDAV: Google\'s CardDAV interface has no '
                        . 'group support at all, neither group vCards nor CATEGORIES. Labels live only in '
                        . 'the People API. Any groups sent to it would be silently lost, which is why the '
                        . 'default here is categories rather than passthrough.',
                    'The documented collection URL is '
                        . 'https://www.googleapis.com/carddav/v1/principals/{username}/lists/default -- but '
                        . 'Google asks that it be discovered rather than hardcoded, since it may change. '
                        . 'It is configured here as the discovery fallback for that reason.',
                ],
                urlFallbackPattern: 'https://www.googleapis.com/carddav/v1/principals/{username}/lists/default/',
                unsupportedReason: 'Google Contacts needs OAuth 2.0, which this app does not support yet. '
                    . 'Google stopped accepting passwords for CardDAV on 14 March 2025, and app-specific '
                    . 'passwords do not work for it either, so there are no credentials that would let '
                    . 'this endpoint connect.',
            ),
            'generic' => new Preset(
                key: 'generic',
                label: 'Generic / custom CardDAV server',
                defaultBaseUrl: '',
                defaultGroupStrategy: 'categories',
                authHint: 'Basic auth with whatever username/password (or application password) your '
                    . 'server expects.',
                quirks: [
                    'Run the capability test after filling in the base URL -- it detects what this '
                        . 'server actually supports and suggests matching settings.',
                ],
            ),
        ];
    }

    public static function get(string $key): Preset
    {
        return self::all()[$key] ?? throw new \InvalidArgumentException("Unknown preset '{$key}'");
    }
}
