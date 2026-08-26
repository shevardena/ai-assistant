import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store } from '@/routes/data-sources/files';

type Props = {
    currentTeamSlug: string;
    dataSourceId: number;
};

export default function SourceFileUploadForm({
    currentTeamSlug,
    dataSourceId,
}: Props) {
    const [selectedFileName, setSelectedFileName] = useState<string | null>(
        null,
    );

    return (
        <Form
            {...store.form([currentTeamSlug, dataSourceId])}
            options={{ preserveScroll: true }}
            className="grid gap-4 rounded-lg border bg-muted/20 p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="source-file">
                            Upload CSV, JSON, or XLSX
                        </Label>
                        <Input
                            id="source-file"
                            name="file"
                            type="file"
                            accept=".csv,.json,.xlsx,text/csv,application/json,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            required
                            onChange={(event) =>
                                setSelectedFileName(
                                    event.target.files?.[0]?.name ?? null,
                                )
                            }
                            data-test="source-file-input"
                        />
                        <p className="text-sm text-muted-foreground">
                            Maximum size: 25 MB.{' '}
                            {selectedFileName ?? 'No file selected.'}
                        </p>
                        <InputError
                            message={errors.file ?? errors.data_source}
                        />
                    </div>
                    <Button
                        type="submit"
                        disabled={processing}
                        data-test="source-file-upload-button"
                    >
                        {processing ? 'Uploading...' : 'Upload file'}
                    </Button>
                </>
            )}
        </Form>
    );
}
