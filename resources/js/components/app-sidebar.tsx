import { Link, usePage } from '@inertiajs/react';
import {
    CreditCard,
    Bot as BotIcon,
    BriefcaseBusiness,
    ChartNoAxesCombined,
    Database,
    BookOpenText,
    HeartPulse,
    Inbox,
    CalendarDays,
    Lightbulb,
    Layers,
    LayoutGrid,
    ListChecks,
    Sparkles,
    UserRound,
    UsersRound,
    GitBranch,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { TeamSwitcher } from '@/components/team-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as actionsIndex } from '@/routes/actions';
import { index as analyticsIndex } from '@/routes/analytics';
import { index as appointmentsIndex } from '@/routes/appointments';
import { index as billingIndex } from '@/routes/billing';
import { index as botsIndex } from '@/routes/bots';
import { index as conversationsIndex } from '@/routes/conversations';
import { index as customersIndex } from '@/routes/customers';
import { index as dataHealthIndex } from '@/routes/data-health';
import { index as dataSourcesIndex } from '@/routes/data-sources';
import { index as datasetsIndex } from '@/routes/datasets';
import { index as dealsIndex } from '@/routes/deals';
import { index as improvementsIndex } from '@/routes/improvements';
import { index as integrationHealthIndex } from '@/routes/integration-health';
import { index as knowledgeIndex } from '@/routes/knowledge';
import { index as knowledgeGapsIndex } from '@/routes/knowledge-gaps';
import { index as leadsIndex } from '@/routes/leads';
import { index as supportTicketsIndex } from '@/routes/support-tickets';
import { index as tasksIndex } from '@/routes/tasks';
import { index as workflowsIndex } from '@/routes/workflows';
import type { NavItem, NavSection } from '@/types';

export function AppSidebar() {
    const page = usePage();
    const { t } = useTranslation();
    const dashboardUrl = page.props.currentTeam
        ? dashboard(page.props.currentTeam.slug)
        : '/';
    const botsUrl = page.props.currentTeam
        ? botsIndex(page.props.currentTeam.slug)
        : '/';
    const billingUrl = page.props.currentTeam
        ? billingIndex(page.props.currentTeam.slug)
        : '/';
    const analyticsUrl = page.props.currentTeam
        ? analyticsIndex(page.props.currentTeam.slug)
        : '/';
    const dataSourcesUrl = page.props.currentTeam
        ? dataSourcesIndex(page.props.currentTeam.slug)
        : '/';
    const datasetsUrl = page.props.currentTeam
        ? datasetsIndex(page.props.currentTeam.slug)
        : '/';
    const conversationsUrl = page.props.currentTeam
        ? conversationsIndex(page.props.currentTeam.slug)
        : '/';
    const knowledgeGapsUrl = page.props.currentTeam
        ? knowledgeGapsIndex(page.props.currentTeam.slug)
        : '/';
    const knowledgeUrl = page.props.currentTeam
        ? knowledgeIndex(page.props.currentTeam.slug)
        : '/';
    const actionsUrl = page.props.currentTeam
        ? actionsIndex(page.props.currentTeam.slug)
        : '/';
    const integrationHealthUrl = page.props.currentTeam
        ? integrationHealthIndex(page.props.currentTeam.slug)
        : '/';
    const dataHealthUrl = page.props.currentTeam
        ? dataHealthIndex(page.props.currentTeam.slug)
        : '/';
    const leadsUrl = page.props.currentTeam
        ? leadsIndex(page.props.currentTeam.slug)
        : '/';
    const customersUrl = page.props.currentTeam
        ? customersIndex(page.props.currentTeam.slug)
        : '/';
    const dealsUrl = page.props.currentTeam
        ? dealsIndex(page.props.currentTeam.slug)
        : '/';
    const appointmentsUrl = page.props.currentTeam
        ? appointmentsIndex(page.props.currentTeam.slug)
        : '/';
    const supportTicketsUrl = page.props.currentTeam
        ? supportTicketsIndex(page.props.currentTeam.slug)
        : '/';
    const tasksUrl = page.props.currentTeam
        ? tasksIndex(page.props.currentTeam.slug)
        : '/';
    const improvementsUrl = page.props.currentTeam
        ? improvementsIndex(page.props.currentTeam.slug)
        : '/';
    const workflowsUrl = page.props.currentTeam
        ? workflowsIndex(page.props.currentTeam.slug)
        : '/';
    const permissions = page.props.currentTeamPermissions ?? {};
    const canView = (permission: string): boolean =>
        permissions[permission] === true;

    const navItems: Record<string, NavItem> = {
        dashboard: {
            title: t('navigation.dashboard'),
            href: dashboardUrl,
            icon: LayoutGrid,
        },
        bots: {
            title: t('navigation.bots'),
            href: botsUrl,
            icon: BotIcon,
            permission: 'bots.view',
        },
        dataSources: {
            title: t('navigation.data_sources'),
            href: dataSourcesUrl,
            icon: Database,
            permission: 'data_sources.view',
        },
        datasets: {
            title: t('navigation.datasets'),
            href: datasetsUrl,
            icon: Layers,
            permission: 'datasets.view',
        },
        workflows: {
            title: t('navigation.workflows'),
            href: workflowsUrl,
            icon: GitBranch,
            permission: 'workflows.view',
        },
        improvements: {
            title: t('navigation.ai_improvements'),
            href: improvementsUrl,
            icon: Sparkles,
            permission: 'improvements.view',
        },
        knowledgeGaps: {
            title: t('navigation.knowledge_gaps'),
            href: knowledgeGapsUrl,
            icon: Lightbulb,
            permission: 'knowledge_gaps.view',
        },
        knowledge: {
            title: 'Knowledge',
            href: knowledgeUrl,
            icon: BookOpenText,
            permission: 'datasets.view',
        },
        conversations: {
            title: t('navigation.conversations'),
            href: conversationsUrl,
            icon: Inbox,
            permission: 'conversations.view',
        },
        leads: {
            title: t('navigation.leads'),
            href: leadsUrl,
            icon: UserRound,
            permission: 'leads.view',
        },
        customers: {
            title: t('navigation.customers'),
            href: customersUrl,
            icon: UsersRound,
            permission: 'customers.view',
        },
        deals: {
            title: t('navigation.deals'),
            href: dealsUrl,
            icon: BriefcaseBusiness,
            permission: 'deals.view',
        },
        tasks: {
            title: t('navigation.tasks'),
            href: tasksUrl,
            icon: ListChecks,
            permission: 'tasks.view',
        },
        appointments: {
            title: t('navigation.appointments'),
            href: appointmentsUrl,
            icon: CalendarDays,
            permission: 'appointments.view',
        },
        supportTickets: {
            title: t('navigation.support_tickets'),
            href: supportTicketsUrl,
            icon: Inbox,
            permission: 'tickets.view',
        },
        analytics: {
            title: t('navigation.analytics'),
            href: analyticsUrl,
            icon: ChartNoAxesCombined,
            permission: 'analytics.view',
        },
        integrationHealth: {
            title: t('navigation.integration_health'),
            href: integrationHealthUrl,
            icon: HeartPulse,
            permission: 'integration_health.view',
        },
        dataHealth: {
            title: t('navigation.data_health'),
            href: dataHealthUrl,
            icon: HeartPulse,
            permission: 'data_health.view',
        },
        actions: {
            title: t('navigation.action_history'),
            href: actionsUrl,
            icon: ListChecks,
            permission: 'actions.view',
        },
        billing: {
            title: t('navigation.billing'),
            href: billingUrl,
            icon: CreditCard,
            permission: 'billing.view',
        },
    } satisfies Record<string, NavItem>;

    const visible = (items: NavItem[]): NavItem[] =>
        items.filter((item) => !item.permission || canView(item.permission));

    const sections: NavSection[] = [
        {
            key: 'home',
            title: t('navigation.home'),
            items: visible([navItems.dashboard]),
        },
        {
            key: 'ai-assistant',
            title: t('navigation.ai_assistant'),
            collapsible: true,
            items: visible([
                navItems.bots,
                navItems.dataSources,
                navItems.datasets,
                navItems.knowledge,
                navItems.workflows,
                navItems.improvements,
                navItems.knowledgeGaps,
            ]),
        },
        {
            key: 'inbox-customers',
            title: t('navigation.inbox_customers'),
            collapsible: true,
            items: visible([
                navItems.conversations,
                navItems.customers,
                navItems.leads,
                navItems.deals,
                navItems.tasks,
                navItems.appointments,
                navItems.supportTickets,
            ]),
        },
        {
            key: 'insights-health',
            title: t('navigation.insights_health'),
            collapsible: true,
            items: visible([
                navItems.analytics,
                navItems.integrationHealth,
                navItems.dataHealth,
                navItems.actions,
            ]),
        },
        {
            key: 'account',
            title: t('navigation.account'),
            items: visible([navItems.billing]),
        },
    ].filter((section) => section.items.length > 0);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboardUrl} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <TeamSwitcher />
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain sections={sections} />
            </SidebarContent>
        </Sidebar>
    );
}
