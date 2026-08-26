import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavSection } from '@/types';

function SectionItems({ section }: { section: NavSection }) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <SidebarGroupContent>
            <SidebarMenu>
                {section.items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            isActive={isCurrentOrParentUrl(item.href)}
                            tooltip={{ children: item.title }}
                        >
                            <Link href={item.href} prefetch>
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroupContent>
    );
}

export function NavMain({ sections = [] }: { sections: NavSection[] }) {
    const { currentUrl, isCurrentOrParentUrl } = useCurrentUrl();
    const { state } = useSidebar();
    const [openSections, setOpenSections] = useState<
        Record<string, { route: string; open: boolean }>
    >({});

    return (
        <>
            {sections
                .filter((section) => section.items.length > 0)
                .map((section) => {
                    const isActive = section.items.some((item) =>
                        isCurrentOrParentUrl(item.href),
                    );

                    if (!section.collapsible) {
                        return (
                            <SidebarGroup
                                className="px-2 py-0"
                                key={section.key}
                            >
                                <SidebarGroupLabel className="px-2 text-[11px] font-semibold tracking-[0.08em] uppercase">
                                    {section.title}
                                </SidebarGroupLabel>
                                <SectionItems section={section} />
                            </SidebarGroup>
                        );
                    }

                    const remembered = openSections[section.key];
                    const isOpen = isActive
                        ? remembered?.route === currentUrl
                            ? remembered.open
                            : true
                        : (remembered?.open ?? false);
                    const effectiveOpen = state === 'collapsed' ? true : isOpen;

                    return (
                        <Collapsible
                            key={section.key}
                            open={effectiveOpen}
                            onOpenChange={(open) => {
                                if (state !== 'collapsed') {
                                    setOpenSections((current) => ({
                                        ...current,
                                        [section.key]: {
                                            route: currentUrl,
                                            open,
                                        },
                                    }));
                                }
                            }}
                            asChild
                        >
                            <SidebarGroup className="px-2 py-0">
                                <SidebarGroupLabel
                                    asChild
                                    className={
                                        isActive
                                            ? 'text-sidebar-foreground'
                                            : undefined
                                    }
                                >
                                    <CollapsibleTrigger
                                        type="button"
                                        aria-expanded={effectiveOpen}
                                        aria-controls={`${section.key}-navigation`}
                                        aria-label={section.title}
                                        className="group/section-label w-full justify-between px-2 text-[11px] font-semibold tracking-[0.08em] uppercase hover:text-sidebar-foreground"
                                    >
                                        <span>{section.title}</span>
                                        <ChevronDown className="size-3.5 transition-transform duration-200 group-data-[state=open]/section-label:rotate-180" />
                                    </CollapsibleTrigger>
                                </SidebarGroupLabel>
                                <CollapsibleContent
                                    id={`${section.key}-navigation`}
                                    className="data-[state=closed]:hidden"
                                >
                                    <SectionItems section={section} />
                                </CollapsibleContent>
                            </SidebarGroup>
                        </Collapsible>
                    );
                })}
        </>
    );
}
