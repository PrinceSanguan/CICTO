/**
 * The illustrative phone beside the scan console.
 *
 * Decorative: it shows a field user what they are meant to do with a handset,
 * which is the whole instruction this page has to convey to somebody who has
 * never used it. It is not a live preview and is hidden below `lg`, where the
 * real viewfinder needs the width.
 */
export function ScanPhone() {
    return (
        <div
            aria-hidden="true"
            className="relative hidden w-[300px] shrink-0 lg:block xl:w-[340px]"
        >
            <div className="rounded-[2.75rem] bg-[#2B2B2F] p-3 shadow-2xl shadow-brand/30">
                <div className="relative overflow-hidden rounded-[2.1rem] bg-[#EAF2FD]">
                    {/* Status bar */}
                    <div className="flex items-center justify-between px-6 pt-3 pb-2 text-[0.7rem] font-semibold text-white">
                        <span className="text-[#1F2937]">9:41</span>
                        <span className="flex items-center gap-1">
                            <svg
                                viewBox="0 0 18 12"
                                className="h-3 w-4 fill-[#1F2937]"
                            >
                                <rect x="0" y="8" width="3" height="4" rx="1" />
                                <rect x="5" y="5" width="3" height="7" rx="1" />
                                <rect
                                    x="10"
                                    y="2"
                                    width="3"
                                    height="10"
                                    rx="1"
                                />
                            </svg>
                            <svg
                                viewBox="0 0 16 12"
                                className="h-3 w-4 fill-[#1F2937]"
                            >
                                <path d="M8 10.5 5.6 8a3.4 3.4 0 0 1 4.8 0L8 10.5Zm0-5a6.8 6.8 0 0 0-4.8 2L1.8 6.1a8.8 8.8 0 0 1 12.4 0L12.8 7.5A6.8 6.8 0 0 0 8 5.5Z" />
                            </svg>
                            <svg
                                viewBox="0 0 26 12"
                                className="h-3 w-6 fill-none"
                            >
                                <rect
                                    x="0.75"
                                    y="0.75"
                                    width="21"
                                    height="10.5"
                                    rx="3"
                                    stroke="#1F2937"
                                    strokeWidth="1.2"
                                />
                                <rect
                                    x="2.5"
                                    y="2.5"
                                    width="17"
                                    height="7"
                                    rx="1.8"
                                    fill="#1F2937"
                                />
                            </svg>
                        </span>
                    </div>

                    {/* App chrome */}
                    <div className="bg-[#9CC6F5] px-4 pt-3 pb-4">
                        <div className="flex items-center gap-3">
                            <div className="size-10 rounded-lg bg-white/70" />
                            <div className="flex-1 space-y-1.5">
                                <div className="h-2 rounded-full bg-white/80" />
                                <div className="h-2 w-4/5 rounded-full bg-white/60" />
                                <div className="h-2 w-2/3 rounded-full bg-white/50" />
                            </div>
                        </div>

                        <div className="mt-4 rounded-xl bg-white p-3">
                            <MiniQr />
                        </div>

                        <div className="mt-3 flex items-center gap-2">
                            <span className="flex size-8 items-center justify-center rounded-lg bg-white">
                                <svg
                                    viewBox="0 0 20 20"
                                    className="size-4 fill-none stroke-[#2F855A] stroke-[2.5]"
                                >
                                    <path
                                        d="m4 10.5 4 4 8-9"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                    />
                                </svg>
                            </span>
                            <span className="flex-1 space-y-1.5">
                                <span className="block h-2 rounded-full bg-white/90" />
                                <span className="block h-2 w-3/4 rounded-full bg-white/70" />
                            </span>
                        </div>
                    </div>

                    {/* Home indicator */}
                    <div className="flex justify-center bg-[#EAF2FD] py-2.5">
                        <span className="h-1 w-24 rounded-full bg-[#1F2937]/70" />
                    </div>
                </div>
            </div>
        </div>
    );
}

/**
 * A decorative QR-like block. Deliberately NOT a real encoded code — a
 * scannable graphic in an illustration is an invitation to scan the wrong
 * thing, and this one resolves to nothing.
 */
function MiniQr() {
    const cells = 21;

    // Deterministic pseudo-random fill: a seeded pattern keeps the mockup
    // identical between renders and between server and client.
    const filled = (row: number, col: number) => {
        const seed = (row * 31 + col * 17 + row * col * 7) % 11;

        return seed > 4;
    };

    const finder = (row: number, col: number) =>
        (row < 7 && col < 7) ||
        (row < 7 && col >= cells - 7) ||
        (row >= cells - 7 && col < 7);

    return (
        <svg viewBox={`0 0 ${cells} ${cells}`} className="w-full">
            <rect width={cells} height={cells} fill="#FFFFFF" />
            {Array.from({ length: cells }, (_, row) =>
                Array.from({ length: cells }, (_, col) =>
                    !finder(row, col) && filled(row, col) ? (
                        <rect
                            key={`${row}-${col}`}
                            x={col}
                            y={row}
                            width="1"
                            height="1"
                            fill="#111111"
                        />
                    ) : null,
                ),
            )}
            {[
                [0, 0],
                [0, cells - 7],
                [cells - 7, 0],
            ].map(([row, col]) => (
                <g key={`${row}-${col}`}>
                    <rect x={col} y={row} width="7" height="7" fill="#111111" />
                    <rect
                        x={col + 1}
                        y={row + 1}
                        width="5"
                        height="5"
                        fill="#FFFFFF"
                    />
                    <rect
                        x={col + 2}
                        y={row + 2}
                        width="3"
                        height="3"
                        fill="#111111"
                    />
                </g>
            ))}
        </svg>
    );
}
