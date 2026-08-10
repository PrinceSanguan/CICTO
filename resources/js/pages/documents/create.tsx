import { Form, Head } from '@inertiajs/react';
import DocumentController from '@/actions/App/Http/Controllers/DocumentController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import documents from '@/routes/documents';
import type { IdNameOption, SelectOption } from '@/types';

type Props = {
    offices: IdNameOption[];
    documentTypes: IdNameOption[];
    priorities: SelectOption[];
    defaultOfficeId: number | null;
};

const SELECT_CLASS =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

/**
 * §5 Submit Document.
 *
 * The spec lists title, department, document type, priority, description,
 * remarks and a file upload as ONE form -- description and remarks are separate
 * fields, not the same box under two names.
 */
export default function CreateDocument({
    offices,
    documentTypes,
    priorities,
    defaultOfficeId,
}: Props) {
    return (
        <>
            <Head title="Submit Document" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Submit Document"
                    description="Registering a document assigns it a control number and a QR code."
                />

                <Form
                    {...DocumentController.store.form()}
                    options={{ preserveScroll: true }}
                    className="max-w-3xl space-y-6"
                    encType="multipart/form-data"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="title">Title</Label>
                                <Input
                                    id="title"
                                    name="title"
                                    required
                                    autoFocus
                                    placeholder="Subject of the document"
                                />
                                <InputError message={errors.title} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="originating_office_id">
                                        Department
                                    </Label>
                                    <select
                                        id="originating_office_id"
                                        name="originating_office_id"
                                        required
                                        defaultValue={defaultOfficeId ?? ''}
                                        className={SELECT_CLASS}
                                    >
                                        <option value="">
                                            Select a department…
                                        </option>
                                        {offices.map((office) => (
                                            <option
                                                key={office.id}
                                                value={office.id}
                                            >
                                                {office.code
                                                    ? `${office.code} — ${office.name}`
                                                    : office.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={errors.originating_office_id}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="document_type_id">
                                        Document type
                                    </Label>
                                    <select
                                        id="document_type_id"
                                        name="document_type_id"
                                        required
                                        defaultValue=""
                                        className={SELECT_CLASS}
                                    >
                                        <option value="">Select a type…</option>
                                        {documentTypes.map((type) => (
                                            <option
                                                key={type.id}
                                                value={type.id}
                                            >
                                                {type.name}
                                                {type.turnaround_days
                                                    ? ` (${type.turnaround_days} days)`
                                                    : ''}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={errors.document_type_id}
                                    />
                                </div>
                            </div>

                            <div className="grid gap-2 sm:max-w-xs">
                                <Label htmlFor="priority">Priority</Label>
                                <select
                                    id="priority"
                                    name="priority"
                                    defaultValue="normal"
                                    className={SELECT_CLASS}
                                >
                                    {priorities.map((priority) => (
                                        <option
                                            key={priority.value}
                                            value={priority.value}
                                        >
                                            {priority.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.priority} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="description">Description</Label>
                                <textarea
                                    id="description"
                                    name="description"
                                    rows={4}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                    placeholder="What is this document about?"
                                />
                                <InputError message={errors.description} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="remarks">Remarks</Label>
                                <textarea
                                    id="remarks"
                                    name="remarks"
                                    rows={2}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                    placeholder="Anything the receiving office should know"
                                />
                                <InputError message={errors.remarks} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="file">Attachment</Label>
                                <Input id="file" name="file" type="file" />
                                <p className="text-xs text-muted-foreground">
                                    PDF, Word, Excel or an image. SVG files are
                                    not accepted.
                                </p>
                                <InputError message={errors.file} />
                            </div>

                            <div className="flex items-center gap-3">
                                <Button type="submit" disabled={processing}>
                                    {processing
                                        ? 'Registering…'
                                        : 'Register document'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

CreateDocument.layout = {
    breadcrumbs: [
        { title: 'Track Documents', href: documents.index() },
        { title: 'Submit Document', href: documents.create() },
    ],
};
