<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    public function rank(): int
    {
        return match ($this) {
            self::Owner => 4,
            self::Admin => 3,
            self::Member => 2,
            self::Viewer => 1,
        };
    }

    public function atLeast(self $role): bool
    {
        return $this->rank() >= $role->rank();
    }

    public function canWrite(): bool
    {
        return $this->atLeast(self::Member);
    }

    /**
     * @return list<string>
     */
    public static function invitable(): array
    {
        return [self::Admin->value, self::Member->value, self::Viewer->value];
    }
}
