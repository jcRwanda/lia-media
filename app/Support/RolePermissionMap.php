<?php

namespace App\Support;

final class RolePermissionMap
{
    public const AREAS = [
        'media/gallery' => [
            'label' => 'Media / Gallery',
            'permissions' => [
                'assets.upload' => 'Upload assets/media',
                'assets.edit' => 'Edit assets',
                'assets.delete' => 'Delete assets',
            ],
        ],
        'albums/sharing' => [
            'label' => 'Albums / Sharing',
            'permissions' => [
                'albums.manage' => 'Manage albums',
                'shares.manage' => 'Manage shares',
                'review.approve' => 'Review/approve',
                'review.publish' => 'Publish',
                'consent.manage' => 'Manage consent',
                'publications.manage' => 'Manage publications',
            ],
        ],
        'downloads' => [
            'label' => 'Downloads',
            'permissions' => [
                'assets.download_original' => 'Download originals',
            ],
        ],
        'material-requests' => [
            'label' => 'Material requests',
            'permissions' => [
                'material-requests.view' => 'View material requests',
                'material-requests.manage' => 'Manage material requests',
                'material-requests.issue' => 'Issue materials',
                'material-requests.return' => 'Return/check-in materials',
            ],
        ],
        'store' => [
            'label' => 'Store hand-out/check-in',
            'permissions' => [
                'material-requests.issue' => 'Hand-out (issue)',
                'material-requests.return' => 'Check-in (return)',
            ],
        ],
        'equipment' => [
            'label' => 'Equipment',
            'permissions' => [
                'equipment.manage' => 'Manage equipment',
                'equipment.edit_serial' => 'Change serial numbers',
                'equipment.delete' => 'Delete equipment',
            ],
        ],
        'users' => [
            'label' => 'Users',
            'permissions' => [
                'users.manage' => 'Manage users',
            ],
        ],
        'roles' => [
            'label' => 'Roles',
            'permissions' => [
                'roles.manage' => 'Manage roles',
            ],
        ],
        'settings' => [
            'label' => 'Settings',
            'permissions' => [
                'settings.manage' => 'Manage settings',
                'audit.view' => 'View audit logs',
            ],
        ],
    ];

    public static function all(): array
    {
        $all = [];
        foreach (self::AREAS as $area => $data) {
            foreach ($data['permissions'] as $perm => $label) {
                $all[$perm] = $label;
            }
        }
        return $all;
    }

    public static function grouped(): array
    {
        return self::AREAS;
    }
}
