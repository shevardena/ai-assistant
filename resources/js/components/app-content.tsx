import { usePage } from '@inertiajs/react';
import * as React from 'react';
import FormErrorSummary from '@/components/form-error-summary';
import { SidebarInset } from '@/components/ui/sidebar';
import type { AppVariant } from '@/types';

type Props = React.ComponentProps<'main'> & {
    variant?: AppVariant;
};

export function AppContent({ variant = 'sidebar', children, ...props }: Props) {
    const { errors } = usePage().props as {
        errors?: Record<string, string | string[]>;
    };
    const content = (
        <>
            <FormErrorSummary errors={errors} className="mx-4 mt-4 md:mx-6" />
            {children}
        </>
    );

    if (variant === 'sidebar') {
        return <SidebarInset {...props}>{content}</SidebarInset>;
    }

    return (
        <main
            className="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4 rounded-xl"
            {...props}
        >
            {content}
        </main>
    );
}
