<?php

namespace App\Support;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;

/**
 * Holds the workspace resolved for the current request (or job).
 */
class CurrentWorkspace
{
    protected ?Workspace $workspace = null;

    protected ?WorkspaceRole $role = null;

    public function set(?Workspace $workspace, ?WorkspaceRole $role = null): void
    {
        $this->workspace = $workspace;
        $this->role = $role;
    }

    public function get(): ?Workspace
    {
        return $this->workspace;
    }

    public function id(): ?int
    {
        return $this->workspace?->id;
    }

    public function role(): ?WorkspaceRole
    {
        return $this->role;
    }

    public function has(): bool
    {
        return $this->workspace !== null;
    }

    public function clear(): void
    {
        $this->workspace = null;
        $this->role = null;
    }
}
