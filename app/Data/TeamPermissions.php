<?php

namespace App\Data;

readonly class TeamPermissions
{
    public function __construct(
        public string $role,
        public string $roleLabel,
        public string $roleDescription,
        public bool $canUpdateTeam,
        public bool $canDeleteTeam,
        public bool $canAddMember,
        public bool $canUpdateMember,
        public bool $canRemoveMember,
        public bool $canCreateInvitation,
        public bool $canCancelInvitation,
        public bool $canManageMembers,
        /** @var array<string, bool> */
        public array $abilities,
    ) {}
}
