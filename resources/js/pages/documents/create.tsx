import { Form, Head, Link } from '@inertiajs/react';
import { ChevronLeft, UploadCloud } from 'lucide-react';
import { useRef, useState } from 'react';
import DocumentController from '@/actions/App/Http/Controllers/DocumentController';
import InputError from '@/components/input-error';
import documents from '@/routes/documents';
import type { IdNameOption, SelectOption } from '@/types';

type Props = {
    offices: IdNameOption[];
    documentTypes: IdNameOption[];
    priorities: SelectOption[];
    defaultOfficeId: number | null;
};

const FIELD =
    'h-12 w-full rounded-md border border-[#DCE4EE] bg-white px-4 text-[15px] text-navy ' +
    'placeholder:text-[#9AA5B4] focus-visible:border-brand focus-visible:ring-2 ' +
    'focus-visible:ring-brand/25 focus-visible:outline-none';

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

            <Link
                href={documents.index()}
                className="inline-flex items-center gap-1 text-sm font-bold text-white/90 transition hover:text-white"
            >
                <ChevronLeft className="size-4" />
                Back to Track Document
            </Link>

            <h1 className="mt-4 text-3xl font-extrabold tracking-tight text-white sm:text-4xl">
                Submit Document
            </h1>
            <p className="mt-1 text-[15px] font-medium text-white/90">
                Fill out the form below to submit a new document.
            </p>

            <Form
                {...DocumentController.store.form()}
                options={{ preserveScroll: true }}
                className="mt-6 rounded-xl bg-white p-6 shadow-xl sm:p-8"
                encType="multipart/form-data"
            >
                {({ processing, errors }) => (
                    <>
                        <h2 className="text-lg font-bold text-link">
                            Document Information
                        </h2>

                        <div className="mt-5 grid gap-5 lg:grid-cols-2 lg:gap-x-8">
                            <Field
                                label="Title"
                                htmlFor="title"
                                required
                                error={errors.title}
                            >
                                <input
                                    id="title"
                                    name="title"
                                    required
                                    autoFocus
                                    placeholder="Enter document title"
                                    className={FIELD}
                                    aria-invalid={
                                        errors.title ? true : undefined
                                    }
                                />
                            </Field>

                            <Field
                                label="Document Type"
                                htmlFor="document_type_id"
                                required
                                error={errors.document_type_id}
                            >
                                <select
                                    id="document_type_id"
                                    name="document_type_id"
                                    required
                                    defaultValue=""
                                    className={FIELD}
                                >
                                    <option value="" disabled>
                                        Select document type
                                    </option>
                                    {documentTypes.map((type) => (
                                        <option key={type.id} value={type.id}>
                                            {type.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <Field
                                label="Department"
                                htmlFor="originating_office_id"
                                required
                                error={errors.originating_office_id}
                            >
                                <select
                                    id="originating_office_id"
                                    name="originating_office_id"
                                    required
                                    defaultValue={defaultOfficeId ?? ''}
                                    className={FIELD}
                                >
                                    <option value="" disabled>
                                        Select Department
                                    </option>
                                    {offices.map((office) => (
                                        <option
                                            key={office.id}
                                            value={office.id}
                                        >
                                            {office.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <Field
                                label="Priority"
                                htmlFor="priority"
                                error={errors.priority}
                            >
                                <select
                                    id="priority"
                                    name="priority"
                                    defaultValue="normal"
                                    className={FIELD}
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
                            </Field>

                            <CountedTextarea
                                label="Description"
                                name="description"
                                placeholder="Enter document description (optional)"
                                // The server's real ceiling. A counter that says
                                // 500 while the rule allows 5000 either wastes
                                // the field or, worse, reads as a hard limit the
                                // user has to fight.
                                max={5000}
                                error={errors.description}
                            />

                            <CountedTextarea
                                label="Remarks"
                                name="remarks"
                                placeholder="Enter any remarks (optional)"
                                max={2000}
                                error={errors.remarks}
                            />
                        </div>

                        <h2 className="mt-8 text-lg font-bold text-link">
                            File Upload
                        </h2>

                        <FileDropzone error={errors.file} />

                        <div className="mt-8 flex flex-wrap items-center justify-end gap-3">
                            <Link
                                href={documents.index()}
                                className="rounded-md border border-[#DCE4EE] px-8 py-3 text-sm font-bold text-navy transition hover:bg-[#F4F7FC]"
                            >
                                Cancel
                            </Link>

                            <button
                                type="submit"
                                disabled={processing}
                                className="rounded-md bg-[#3B72C4] px-10 py-3 text-sm font-bold text-white transition hover:bg-[#31629F] disabled:cursor-not-allowed disabled:opacity-70"
                            >
                                {processing ? 'Submitting…' : 'Submit'}
                            </button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

function Field({
    label,
    htmlFor,
    required = false,
    error,
    children,
}: {
    label: string;
    htmlFor: string;
    required?: boolean;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <label
                htmlFor={htmlFor}
                className="block text-sm font-bold text-navy"
            >
                {label}
                {required && (
                    <span className="text-danger" aria-hidden="true">
                        *
                    </span>
                )}
                {required && <span className="sr-only"> (required)</span>}
            </label>
            <div className="mt-1.5">{children}</div>
            <InputError message={error} className="mt-1" />
        </div>
    );
}

/** A textarea with the live character counter from the design. */
function CountedTextarea({
    label,
    name,
    placeholder,
    max,
    error,
}: {
    label: string;
    name: string;
    placeholder: string;
    max: number;
    error?: string;
}) {
    const [value, setValue] = useState('');

    return (
        <div>
            <label htmlFor={name} className="block text-sm font-bold text-navy">
                {label}
            </label>

            <div className="relative mt-1.5">
                <textarea
                    id={name}
                    name={name}
                    rows={4}
                    maxLength={max}
                    value={value}
                    onChange={(event) => setValue(event.target.value)}
                    placeholder={placeholder}
                    className="w-full resize-y rounded-md border border-[#DCE4EE] bg-white px-4 py-3 pb-7 text-[15px] text-navy placeholder:text-[#9AA5B4] focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-brand/25 focus-visible:outline-none"
                />

                {/*
                    aria-hidden: the count is decoration for a sighted user, and
                    a live region announcing every keystroke would be unusable.
                    maxLength already stops the field at the limit.
                */}
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute right-3 bottom-2.5 text-xs text-[#9AA5B4]"
                >
                    {value.length}/{max}
                </span>
            </div>

            <InputError message={error} className="mt-1" />
        </div>
    );
}

/**
 * Drag-and-drop upload.
 *
 * Wraps a real `<input type="file">` rather than replacing it: the input stays
 * in the DOM, keeps its name, and is what the form actually posts. Dropping a
 * file assigns it through a DataTransfer, so keyboard users, screen readers and
 * browsers without drag support all still get a working file picker.
 */
function FileDropzone({ error }: { error?: string }) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);
    const [fileName, setFileName] = useState<string | null>(null);

    const accept = (files: FileList | null) => {
        if (!files || files.length === 0) {
            return;
        }

        const input = inputRef.current;

        if (input) {
            const transfer = new DataTransfer();
            transfer.items.add(files[0]);
            input.files = transfer.files;
        }

        setFileName(files[0].name);
    };

    return (
        <div className="mt-3">
            {/*
             * Marked optional, against the mockup, which shows an asterisk.
             *
             * A registry whose whole purpose is tracking paper folders between
             * offices has to be able to register a document that has not been
             * scanned yet -- that is the common case at a receiving counter.
             * StoreDocumentRequest accordingly validates `file` as nullable, so
             * the asterisk was promising an enforcement that does not exist and
             * never should. If the client does want an attachment on every
             * registration, the rule changes here and in StoreDocumentRequest
             * together, never in only one of them.
             */}
            <label htmlFor="file" className="block text-sm font-bold text-navy">
                Upload File
                <span className="ml-1 text-xs font-semibold text-copy">
                    (optional)
                </span>
            </label>

            <div
                onDragOver={(event) => {
                    event.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(event) => {
                    event.preventDefault();
                    setDragging(false);
                    accept(event.dataTransfer.files);
                }}
                className={`mt-2 rounded-md border-2 border-dashed px-6 py-10 text-center transition ${
                    dragging
                        ? 'border-brand bg-[#EEF4FD]'
                        : 'border-[#C9D4E2] bg-white'
                }`}
            >
                <UploadCloud
                    aria-hidden="true"
                    className="mx-auto size-10 text-navy"
                    strokeWidth={2}
                />

                <p className="mt-2 text-[15px] text-copy">
                    Drag and drop your file here
                </p>
                <p className="text-[15px] text-copy">
                    or{' '}
                    <button
                        type="button"
                        onClick={() => inputRef.current?.click()}
                        className="font-medium text-link underline-offset-2 hover:underline"
                    >
                        click to browse
                    </button>
                </p>

                {fileName && (
                    <p className="mt-3 text-sm font-bold text-navy">
                        {fileName}
                    </p>
                )}

                <input
                    ref={inputRef}
                    id="file"
                    name="file"
                    type="file"
                    onChange={(event) =>
                        setFileName(event.target.files?.[0]?.name ?? null)
                    }
                    className="sr-only"
                />
            </div>

            <p className="mt-2 text-xs text-copy">
                PDF, Word, Excel or an image. SVG files are not accepted.
            </p>

            <InputError message={error} className="mt-1" />
        </div>
    );
}

CreateDocument.layout = {
    breadcrumbs: [
        { title: 'Track Documents', href: documents.index() },
        { title: 'Submit Document', href: documents.create() },
    ],
};
