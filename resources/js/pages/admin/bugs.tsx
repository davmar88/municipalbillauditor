import { Head, Link } from '@inertiajs/react';
import { Bug, LogIn } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { act, shortWhen, speciesEmoji } from '@/lib/game';
import { bugs, playAs } from '@/routes/admin';
import { update } from '@/routes/admin/bugs';
import type { Species } from '@/types/game';

type Status = 'open' | 'fixed' | 'closed';

type Report = {
    id: number;
    title: string;
    description: string;
    page: string | null;
    device: string | null;
    status: Status;
    note: string | null;
    at: string;
    user: { id: number; name: string; email: string; admin: boolean };
    player: { id: number; name: string; species: Species } | null;
    attachment: { url: string; video: boolean } | null;
    /** For a cheating report: the rule and who it's about. */
    rule: string | null;
    reported: {
        id: number;
        name: string;
        species: Species;
        userId: number;
        userEmail: string | null;
        admin: boolean;
    } | null;
};

type Props = {
    status: Status;
    kind: 'bug' | 'cheat';
    counts: Partial<Record<Status, number>>;
    openCheats: number;
    rules: Record<string, { title: string }>;
    reports: Report[];
};

/** Bug reports from players: read, watch, play as them to see it, and mark fixed. */
export default function BugsPage({
    status,
    kind,
    counts,
    openCheats,
    rules,
    reports,
}: Props) {
    return (
        <>
            <Head title="Bug reports" />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-col gap-2">
                    <h1 className="flex items-center gap-2 text-2xl font-extrabold tracking-tight">
                        <Bug className="size-6" aria-hidden /> Bug reports
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        "Play as" signs you in as that player to see the bug as
                        they do. A banner stays on every page with a button back
                        to your own account. Anything you do while playing as
                        them is done as them.
                    </p>
                    <nav className="flex gap-2">
                        {(
                            [
                                ['bug', 'Bugs'],
                                ['cheat', `Cheating (${openCheats} open)`],
                            ] as const
                        ).map(([value, label]) => (
                            <Link
                                key={value}
                                href={bugs({ query: { kind: value } })}
                                className={`rounded-md border px-3 py-1 text-sm font-semibold ${value === kind ? 'border-primary bg-primary/10' : ''}`}
                            >
                                {label}
                            </Link>
                        ))}
                    </nav>
                    <nav className="flex gap-2">
                        {(['open', 'fixed', 'closed'] as Status[]).map((s) => (
                            <Link
                                key={s}
                                href={bugs({ query: { status: s, kind } })}
                                className={`rounded-full border px-3 py-1 text-sm font-semibold capitalize ${s === status ? 'border-primary bg-primary/10' : ''}`}
                            >
                                {s} ({counts[s] ?? 0})
                            </Link>
                        ))}
                    </nav>
                </header>

                {reports.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-sm text-muted-foreground">
                        No {status} reports.
                    </p>
                ) : (
                    <ul className="flex flex-col gap-4">
                        {reports.map((report) => (
                            <ReportCard
                                key={report.id}
                                report={report}
                                rules={rules}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

function ReportCard({
    report,
    rules,
}: {
    report: Report;
    rules: Props['rules'];
}) {
    const [note, setNote] = useState(report.note ?? '');

    return (
        <li className="flex flex-col gap-3 rounded-xl border bg-card p-4 text-sm">
            <div className="flex flex-wrap items-baseline gap-2">
                <h2 className="text-base font-bold">
                    #{report.id} {report.title}
                </h2>
                <span className="text-xs text-muted-foreground">
                    {shortWhen(report.at)}
                </span>
            </div>
            <p className="text-xs text-muted-foreground">
                User #{report.user.id} · {report.user.name} ·{' '}
                {report.user.email}
                {report.player && (
                    <>
                        {' '}
                        · animal #{report.player.id}{' '}
                        {speciesEmoji(report.player.species)}{' '}
                        {report.player.name}
                    </>
                )}
                {report.page && <> · on {report.page}</>}
            </p>
            {report.reported && (
                <p className="rounded-lg border border-red-500/40 bg-red-500/10 px-3 py-2">
                    🚩 About animal #{report.reported.id}{' '}
                    {speciesEmoji(report.reported.species)}{' '}
                    <strong>{report.reported.name}</strong> (user #
                    {report.reported.userId}
                    {report.reported.userEmail
                        ? `, ${report.reported.userEmail}`
                        : ''}
                    ) · rule <strong>{report.rule}</strong>
                    {report.rule && rules[report.rule]
                        ? `: ${rules[report.rule].title}`
                        : ''}
                </p>
            )}
            <p className="whitespace-pre-wrap">{report.description}</p>
            {report.attachment &&
                (report.attachment.video ? (
                    <video
                        src={report.attachment.url}
                        controls
                        preload="metadata"
                        className="max-h-96 w-full rounded-lg border bg-black"
                    />
                ) : (
                    <a
                        href={report.attachment.url}
                        target="_blank"
                        rel="noreferrer"
                    >
                        <img
                            src={report.attachment.url}
                            alt={`Attached to report ${report.id}`}
                            className="max-h-96 rounded-lg border"
                        />
                    </a>
                ))}
            {report.device && (
                <p className="text-xs text-muted-foreground">{report.device}</p>
            )}
            <Textarea
                rows={2}
                value={note}
                onChange={(e) => setNote(e.target.value)}
                placeholder="A note the player sees with their report"
            />
            <div className="flex flex-wrap gap-2">
                {!report.user.admin && (
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => act(playAs.url(report.user.id))}
                    >
                        <LogIn className="size-4" aria-hidden /> Play as{' '}
                        {report.player?.name ?? report.user.name}
                    </Button>
                )}
                {report.reported && !report.reported.admin && (
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => act(playAs.url(report.reported!.userId))}
                    >
                        <LogIn className="size-4" aria-hidden /> Play as{' '}
                        {report.reported.name}
                    </Button>
                )}
                {(['open', 'fixed', 'closed'] as Status[])
                    .filter((s) => s !== report.status)
                    .map((s) => (
                        <Button
                            key={s}
                            size="sm"
                            variant={s === 'fixed' ? 'default' : 'outline'}
                            onClick={() =>
                                act(
                                    update.url(report.id),
                                    { status: s, note },
                                    'put',
                                )
                            }
                        >
                            Mark {s}
                        </Button>
                    ))}
            </div>
        </li>
    );
}
