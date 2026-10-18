import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/native-select';
import { act, fromNow, shortWhen } from '@/lib/game';
import { dismiss, mute } from '@/routes/staff/reports';

type Props = {
    reports: {
        id: number;
        reason: string;
        note: string | null;
        at: string;
        reporter: string;
        writer: {
            name: string;
            mutedUntil: string | null;
            mutedBefore: number;
        };
        message: { body: string; translation: string | null; at: string };
        before: { id: number; from: string; body: string; at: string }[];
    }[];
    muteDays: number[];
};

/**
 * Private messages their readers reported, with the few messages before
 * each for context. Nothing else from the conversation is shown.
 */
export default function Reports({ reports, muteDays }: Props) {
    return (
        <>
            <Head title="Reported messages" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4 md:p-6">
                <h1 className="text-2xl font-extrabold tracking-tight">
                    Reported messages
                </h1>
                <p className="text-sm text-muted-foreground">
                    Let a message stand, or hide it and stop the writer writing
                    in messages and chat for some days. Mute for threats, hate,
                    scams, real politics, real people and contact details.
                </p>
                {reports.length === 0 ? (
                    <p className="text-muted-foreground">Nothing waiting.</p>
                ) : (
                    <ul className="flex flex-col gap-4">
                        {reports.map((report) => (
                            <Report
                                key={report.id}
                                report={report}
                                muteDays={muteDays}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

function Report({
    report,
    muteDays,
}: {
    report: Props['reports'][number];
    muteDays: number[];
}) {
    const [days, setDays] = useState(muteDays[2] ?? muteDays[0]);

    return (
        <li className="flex flex-col gap-3 rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-semibold">{report.reason}</p>
                <p className="text-xs text-muted-foreground">
                    Reported by {report.reporter} · {shortWhen(report.at)}
                </p>
            </div>
            {report.note && (
                <p className="text-sm text-muted-foreground">"{report.note}"</p>
            )}

            <ol className="flex flex-col gap-2 rounded-lg bg-muted/40 p-3 text-sm">
                {report.before.map((earlier) => (
                    <li key={earlier.id} className="text-muted-foreground">
                        <span className="font-semibold">{earlier.from}:</span>{' '}
                        <span className="break-words whitespace-pre-wrap">
                            {earlier.body}
                        </span>
                    </li>
                ))}
                <li className="rounded-md border border-orange-500/40 bg-orange-500/10 px-2 py-1.5">
                    <span className="font-semibold">{report.writer.name}:</span>{' '}
                    <span className="break-words whitespace-pre-wrap">
                        {report.message.body}
                    </span>
                    {report.message.translation && (
                        <span className="mt-1 block text-cyan-200">
                            Translated: {report.message.translation}
                        </span>
                    )}
                </li>
            </ol>

            <p className="text-xs text-muted-foreground">
                {report.writer.name}{' '}
                {report.writer.mutedUntil
                    ? `is muted, free ${fromNow(report.writer.mutedUntil)}`
                    : 'is not muted'}
                {report.writer.mutedBefore > 0 &&
                    ` · muted over ${report.writer.mutedBefore} earlier ${report.writer.mutedBefore === 1 ? 'report' : 'reports'}`}
            </p>

            <div className="flex flex-wrap items-center gap-2">
                <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => act(dismiss.url(report.id))}
                >
                    Let it stand
                </Button>
                <span className="ml-auto flex items-center gap-2">
                    <NativeSelect
                        value={days}
                        className="w-28"
                        aria-label="Days to mute for"
                        onChange={(e) => setDays(Number(e.target.value))}
                    >
                        {muteDays.map((option) => (
                            <option key={option} value={option}>
                                {option} {option === 1 ? 'day' : 'days'}
                            </option>
                        ))}
                    </NativeSelect>
                    <Button
                        size="sm"
                        variant="destructive"
                        onClick={() => act(mute.url(report.id), { days })}
                    >
                        Hide and mute
                    </Button>
                </span>
            </div>
        </li>
    );
}
