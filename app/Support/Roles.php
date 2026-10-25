<?php

namespace App\Support;

/** Staff roles and what each may do. Admin implicitly has every ability. */
final class Roles
{
    public const ADMIN = 'admin';

    public const MULTIMEDIA = 'multimedia';

    public const COMMUNICATIONS = 'communications';

    public const MANAGEMENT = 'management';

    public const STOREKEEPER = 'storekeeper';

    public const ALL = [self::ADMIN, self::MULTIMEDIA, self::COMMUNICATIONS, self::MANAGEMENT, self::STOREKEEPER];

    /** @var array<string, list<string>> */
    public const ABILITIES = [
        'library.view' => [self::MULTIMEDIA, self::COMMUNICATIONS, self::MANAGEMENT],
        'assets.upload' => [self::MULTIMEDIA],
        'assets.edit' => [self::MULTIMEDIA, self::COMMUNICATIONS],
        'assets.delete' => [],
        'assets.download_original' => [self::MULTIMEDIA, self::COMMUNICATIONS, self::MANAGEMENT],
        'taxonomy.manage' => [self::MULTIMEDIA, self::COMMUNICATIONS],
        'albums.manage' => [self::MULTIMEDIA, self::COMMUNICATIONS],
        'shares.manage' => [self::MULTIMEDIA, self::COMMUNICATIONS],
        'review.approve' => [self::COMMUNICATIONS, self::MANAGEMENT],
        'review.publish' => [self::COMMUNICATIONS],
        'consent.manage' => [self::COMMUNICATIONS],
        'publications.manage' => [self::COMMUNICATIONS],
        'dashboard.view' => [self::MULTIMEDIA, self::COMMUNICATIONS, self::MANAGEMENT],
        'audit.view' => [self::MANAGEMENT],
        'users.manage' => [],
        'settings.manage' => [],
        'material-requests.view' => [self::COMMUNICATIONS, self::MANAGEMENT, self::STOREKEEPER],
        'material-requests.manage' => [self::COMMUNICATIONS, self::MANAGEMENT],
        'material-requests.issue' => [self::STOREKEEPER, self::MANAGEMENT, self::COMMUNICATIONS],
        'material-requests.return' => [self::STOREKEEPER, self::MANAGEMENT, self::COMMUNICATIONS],
        'equipment.manage' => [self::STOREKEEPER, self::MANAGEMENT, self::COMMUNICATIONS],
    ];

    public static function allows(?string $role, string $ability): bool
    {
        if ($role === self::ADMIN) {
            return true;
        }

        return in_array($role, self::ABILITIES[$ability] ?? [], true);
    }

    /** @return list<string> */
    public static function abilitiesFor(?string $role): array
    {
        return array_values(array_filter(array_keys(self::ABILITIES), fn ($a) => self::allows($role, $a)));
    }
}
