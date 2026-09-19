import { Form, Head, Link } from '@inertiajs/react';
import { ChevronLeft, UploadCloud } from 'lucide-react';
import { useRef, useState } from 'react';
import DocumentController from '@/actions/App/Http/Controllers/DocumentController';
import {
    OfficeRoutePicker,
    routeError,
} from '@/components/documents/office-route-picker';
import { UploadErrorDialog } from '@/components/documents/upload-error-dialog';
import InputError from '@/components/input-error';
import { useUploadGuard } from '@/hooks/use-upload-guard';
import documents from '@/routes/documents';
import type { IdNameOption, SelectOption } from '@/types';

type Props = {
    offices: IdNameOption[];
    documentTypes: IdNameOption[];
    priorities: SelectOption[];
    defaultOfficeId: number | null;
};

/*
 * min-w-0 is not cosmetic here. Three of the controls wearing this class are
 * <select>s, and a select's min-content width is its WIDEST OPTION -- so the
 * department dropdown, holding office names up to 84 characters, demanded a
 * column wider than the page and got it, because grid children default to
 * `min-width: auto` and `w-full` is only a percentage. See OfficeRoutePicker
 * for the full account of what that broke.
 */
const FIELD =
    'h-12 w-full min-w-0 rounded-md border border-[#DCE4EE] bg-white px-4 text-[15px] text-navy ' +
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
    /*
     * Departments, in visiting order. The first is the ORIGINATING office --
     * it stamps the control number prefix and is where the folder starts --
     * and any after it are queued as the routing plan, so one submit can name
     * every department the document has to pass through.
     *
     * Not `useState(defaultOfficeId ? ... )`: office id 0 is impossible, but a
     * falsy check on an id is the kind of thing that quietly breaks the day the
     * ids change shape. The prop is null or an id, so compare against null.
     */
    const [departmentIds, setDepartmentIds] = useState<number[]>(
        defaultOfficeId === null ? [] : [defaultOfficeId],
    );

    const upload = useUploadGuard();

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
                onError={(errors) => upload.reject(errors.file)}
            >
                {({ processing, errors, clearErrors }) => (
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
                                htmlFor="office_ids"
                                required
                                error={
                                    routeError(errors, 'office_ids') ??
                                    errors.originating_office_id
                                }
                            >
                                <OfficeRoutePicker
                                    offices={offices}
                                    value={departmentIds}
                                    onChange={setDepartmentIds}
                                    disabled={processing}
                                    id="office_ids"
                                    // The Field above already labels this
                                    // control, with the asterisk and the
                                    // screen-reader "(required)" the rest of
                                    // the form uses. A second label would name
                                    // the same input twice.
                                    label={null}
                                    noun="department"
                                    className="grid gap-3"
                                    selectClassName={`${FIELD} disabled:opacity-60`}
                                    /*
                                        Row 1 is the submitter's own office
                                        and stays there (client, 2026-09-19).
                                        Only when the server named one: a
                                        user whose office is inactive gets an
                                        empty list, and StoreDocumentRequest
                                        refuses whatever they put first.
                                    */
                                    lockFirst={defaultOfficeId !== null}
                                    hint={(first) => (
                                        <>
                                            The document is registered under{' '}
                                            {first.name} and moves to the next
                                            department each time it is received.
                                        </>
                                    )}
                                />

                                {/*
                                    No "How should they get it?" any more. The
                                    client removed "All at the same time" on
                                    2026-09-19 -- "mag stick na po sa one after
                                    another" -- so every department after the
                                    first is visited in turn, in the order
                                    listed, and there is no choice to post.
                                */}

                                {/*
                                    What the form actually posts.

                                    <Form> serialises the DOM, so the picker's
                                    state has to exist as real inputs -- one per
                                    department, in order, because that order is
                                    the route. Nothing chosen means no inputs at
                                    all, which is exactly the payload the
                                    server's `required` rule is there to refuse;
                                    a hidden input cannot carry the browser's
                                    own `required` (an unfocusable invalid
                                    control blocks the submit silently), so the
                                    check that matters is the one on the server.
                                */}
                                {departmentIds.map((id) => (
                                    <input
                                        key={id}
                                        type="hidden"
                                        name="office_ids[]"
                                        value={id}
                                    />
                                ))}
                            </Field>

                            {/*
                                Required, with nothing pre-selected, and that
                                is the client's decision of 2026-09-19: the
                                submitter has to say how soon, rather than
                                inherit a default nobody chose. The wording is
                                the client's own paper form.
                            */}
                            <Field
                                label="Priority"
                                htmlFor="priority"
                                required
                                error={errors.priority}
                            >
                                <select
                                    id="priority"
                                    name="priority"
                                    required
                                    defaultValue=""
                                    className={FIELD}
                                    aria-invalid={
                                        errors.priority ? true : undefined
                                    }
                                >
                                    <option value="" disabled>
                                        Select priority
                                    </option>
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

                        <FileDropzone
                            error={errors.file}
                            check={upload.check}
                            accepted={`${upload.dialog.allowed}, up to ${upload.dialog.maxLabel}.`}
                            onFileChosen={() => clearErrors('file')}
                        />

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

            <UploadErrorDialog {...upload.dialog} />
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
        <div className="min-w-0">
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
function FileDropzone({
    error,
    check,
    accepted,
    onFileChosen,
}: {
    error?: string;
    /** useUploadGuard's check: a refused file opens the pop-up and is not kept. */
    check: (file: File | null | undefined) => File | null;
    /** "PDF, Word, Excel, PNG or JPG, up to 10 MB." */
    accepted: string;
    /*
     * Clears the server-side error for this field.
     *
     * "The file field is required." stayed on screen after the user picked a
     * file, because a validation error lives until the next response -- so the
     * form read as still broken while being perfectly submittable. This clears
     * the actual error state rather than hiding the message, so a genuinely
     * new error from the next submit still appears.
     */
    onFileChosen: () => void;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);
    const [fileName, setFileName] = useState<string | null>(null);

    const accept = (files: FileList | null) => {
        if (!files || files.length === 0) {
            return;
        }

        const file = check(files[0]);
        const input = inputRef.current;

        // A refused file is cleared rather than left selected behind the
        // pop-up, so the form cannot go on to send it anyway.
        if (file === null) {
            if (input) {
                input.value = '';
            }

            setFileName(null);

            return;
        }

        if (input) {
            const transfer = new DataTransfer();
            transfer.items.add(file);
            input.files = transfer.files;
        }

        setFileName(file.name);
        onFileChosen();
    };

    return (
        <div className="mt-3">
            {/*
             * Required, matching the design -- and enforced in
             * StoreDocumentRequest, never here alone. An asterisk the server
             * does not check is the exact defect QA found: the form accepted
             * submissions with no file at all while promising otherwise.
             *
             * Worth the client knowing what this costs: a receiving counter can
             * no longer register a paper folder that has not been scanned yet.
             * If that turns out to matter, the rule relaxes in both places
             * together.
             */}
            <label htmlFor="file" className="block text-sm font-bold text-navy">
                Upload File
                <span className="text-danger" aria-hidden="true">
                    *
                </span>
                <span className="sr-only"> (required)</span>
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
                    onChange={(event) => {
                        // Cancelling the picker empties the input.
                        if (!event.target.files?.length) {
                            setFileName(null);

                            return;
                        }

                        accept(event.target.files);
                    }}
                    className="sr-only"
                />
            </div>

            <p className="mt-2 text-xs text-copy">
                {accepted} SVG files are not accepted.
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
