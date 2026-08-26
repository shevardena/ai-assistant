import type { Auth } from '@/types/auth';
import type { LocaleMetadata } from '@/types/locales';
import type { Team, WorkspaceBilling } from '@/types/teams';
import type { TeamPermissionMap } from '@/types/teams';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            locale: string;
            supportedLocales: LocaleMetadata[];
            auth: Auth;
            sidebarOpen: boolean;
            currentTeam: Team | null;
            currentTeamPermissions: TeamPermissionMap;
            currentTeamUnreadNotificationsCount: number;
            teams: Team[];
            workspaceBilling: WorkspaceBilling | null;
            [key: string]: unknown;
        };
    }
}
