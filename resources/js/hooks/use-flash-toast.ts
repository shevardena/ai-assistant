import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

export function useFlashToast(): void {
    const lastErrorSignature = useRef<string | null>(null);

    useEffect(() => {
        const removeFlashListener = router.on('flash', ({ detail }) => {
            const data = detail.flash?.toast as FlashToast | undefined;

            if (!data) {
                return;
            }

            toast[data.type](data.message);
        });

        const removeNavigateListener = router.on('navigate', ({ detail }) => {
            const errors = (detail.page.props as { errors?: unknown }).errors;
            const messages = Object.values(
                errors && typeof errors === 'object'
                    ? (errors as Record<string, unknown>)
                    : {},
            ).flatMap((value) =>
                Array.isArray(value)
                    ? value.filter(
                          (item): item is string => typeof item === 'string',
                      )
                    : typeof value === 'string'
                      ? [value]
                      : [],
            );
            const uniqueMessages = Array.from(new Set(messages));

            if (uniqueMessages.length === 0) {
                lastErrorSignature.current = null;

                return;
            }

            const signature = JSON.stringify(uniqueMessages);

            if (signature === lastErrorSignature.current) {
                return;
            }

            lastErrorSignature.current = signature;
            toast.error('Please fix the errors in the form.', {
                description: uniqueMessages.join(' • '),
            });
        });

        const removeHttpExceptionListener = router.on(
            'httpException',
            ({ detail }) => {
                const responseData = detail.response.data;
                const message =
                    typeof responseData === 'object' &&
                    responseData !== null &&
                    typeof responseData.message === 'string'
                        ? responseData.message
                        : 'The request failed (' +
                          detail.response.status +
                          ').';

                toast.error(message);
            },
        );

        const removeNetworkErrorListener = router.on(
            'networkError',
            ({ detail }) => {
                toast.error(
                    detail.error.message ||
                        'The request could not be completed. Please try again.',
                );
            },
        );

        return () => {
            removeFlashListener();
            removeNavigateListener();
            removeHttpExceptionListener();
            removeNetworkErrorListener();
        };
    }, []);
}
