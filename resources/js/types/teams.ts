import type { PlanDefinition } from '@/types/billing';

export type TeamRole =
    | 'owner'
    | 'admin'
    | 'support_agent'
    | 'content_manager'
    | 'analyst'
    | 'developer'
    | 'member';

export type Team = {
    id: number;
    name: string;
    slug: string;
    isPersonal: boolean;
    role?: TeamRole;
    roleLabel?: string;
    isCurrent?: boolean;
};

export type WorkspaceBilling = {
    free_available: boolean;
    plans: PlanDefinition[];
};

export type TeamMember = {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
    role: TeamRole;
    role_label: string;
    role_description?: string;
};

export type TeamInvitation = {
    code: string;
    email: string;
    role: TeamRole;
    role_label: string;
    created_at: string;
};

export type TeamInvitationContext = {
    code: string;
    teamName: string;
};

export type DashboardInvitation = {
    code: string;
    inviterName: string;
    team: {
        name: string;
        slug: string;
    };
};

export type TeamPermissions = {
    role: TeamRole | '';
    roleLabel: string;
    roleDescription: string;
    canUpdateTeam: boolean;
    canDeleteTeam: boolean;
    canAddMember: boolean;
    canUpdateMember: boolean;
    canRemoveMember: boolean;
    canCreateInvitation: boolean;
    canCancelInvitation: boolean;
    canManageMembers: boolean;
    abilities: Record<string, boolean>;
};

export type RoleOption = {
    value: TeamRole;
    label: string;
    description: string;
};

export type TeamPermissionMap = Record<string, boolean>;
