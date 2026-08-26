import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Database, FileText, Share2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import DataSourceForm from '@/components/data-source-form';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index, store } from '@/routes/data-sources';
import { create as createApi } from '@/routes/data-sources/api';
import { file as createFile } from '@/routes/data-sources/create';
import { create as createGraphql } from '@/routes/data-sources/graphql';

type Props = {
    sourceType?: 'file';
    templateContext?: Record<string, string | number>;
};

export default function DataSourcesCreate({ sourceType, templateContext }: Props) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;
    const query = templateContext && Object.keys(templateContext).length > 0
        ? { query: templateContext }
        : undefined;

    if (!currentTeam) {
        return null;
    }

    if (sourceType === 'file') {
        return (
            <>
                <Head title={t('common.uploaded_file')} />
                <h1 className="sr-only">{t('common.uploaded_file')}</h1>

                <div className="max-w-3xl space-y-6 p-4 md:p-6">
                    <div className="flex items-start gap-3">
                        <Button variant="ghost" size="icon" asChild>
                            <Link
                                href={index(currentTeam.slug).url}
                                aria-label={t('common.back')}
                            >
                                <ArrowLeft />
                            </Link>
                        </Button>
                        <Heading
                            variant="small"
                            title={t('data_source_chooser.file_title')}
                            description={t(
                                'data_source_chooser.file_description',
                            )}
                        />
                    </div>

                    <DataSourceForm
                        action={store.form(currentTeam.slug)}
                        currentTeamSlug={currentTeam.slug}
                        sourceType="file"
                        submitLabel={t('common.create_data_source')}
                    />
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={t('data_source_chooser.title')} />
            <h1 className="sr-only">{t('data_source_chooser.title')}</h1>

            <div className="max-w-5xl space-y-8 p-4 md:p-6">
                <div className="flex items-start gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link
                            href={index(currentTeam.slug).url}
                            aria-label={t('common.back')}
                        >
                            <ArrowLeft />
                        </Link>
                    </Button>
                    <Heading
                        variant="small"
                        title={t('data_source_chooser.title')}
                        description={t('data_source_chooser.description')}
                    />
                </div>

                <div className="grid gap-5 md:grid-cols-2">
                    <Card className="flex flex-col">
                        <CardHeader>
                            <div className="flex size-11 items-center justify-center rounded-xl bg-muted text-muted-foreground">
                                <FileText className="size-6" />
                            </div>
                            <CardTitle>
                                {t('data_source_chooser.file_title')}
                            </CardTitle>
                            <p className="text-sm text-muted-foreground">
                                {t('data_source_chooser.file_description')}
                            </p>
                        </CardHeader>
                        <CardContent className="flex flex-1 flex-col gap-5">
                            <p className="text-sm text-muted-foreground">
                                {t('data_source_chooser.file_help')}
                            </p>
                            <Button className="mt-auto w-fit" asChild>
                                <Link href={createFile(currentTeam.slug).url}>
                                    {t('data_source_chooser.continue')}
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>

                    <Card className="flex flex-col border-primary/30">
                        <CardHeader>
                            <div className="flex size-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                <Database className="size-6" />
                            </div>
                            <CardTitle>
                                {t('data_source_chooser.api_title')}
                            </CardTitle>
                            <p className="text-sm text-muted-foreground">
                                {t('data_source_chooser.api_description')}
                            </p>
                        </CardHeader>
                        <CardContent className="flex flex-1 flex-col gap-5">
                            <p className="text-sm text-muted-foreground">
                                {t('data_source_chooser.api_help')}
                            </p>
                            <Button className="mt-auto w-fit" asChild>
                                <Link href={createApi(currentTeam.slug, query).url}>
                                    {t('data_source_chooser.continue')}
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>

                    <Card className="flex flex-col border-violet-500/30">
                        <CardHeader>
                            <div className="flex size-11 items-center justify-center rounded-xl bg-violet-500/10 text-violet-600">
                                <Share2 className="size-6" />
                            </div>
                            <CardTitle>{t('data_source_chooser.graphql_title')}</CardTitle>
                            <p className="text-sm text-muted-foreground">{t('data_source_chooser.graphql_description')}</p>
                        </CardHeader>
                        <CardContent className="flex flex-1 flex-col gap-5">
                            <p className="text-sm text-muted-foreground">{t('data_source_chooser.graphql_help')}</p>
                            <Button className="mt-auto w-fit" asChild>
                                <Link href={createGraphql(currentTeam.slug, query).url}>{t('data_source_chooser.continue')}</Link>
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
