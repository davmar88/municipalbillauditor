import { Head, useForm } from '@inertiajs/react';
import { Bug } from 'lucide-react';
import InputError from '@/components/input-error';
import { GameBar } from '@/components/game/game-bar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { shortWhen } from '@/lib/game';
import { store } from '@/routes/play/bugs';

type Props = {
    /** The page they came from, if the link carried it. */
    from: string;
    maxMb: number;
    mine: {
        id: number;
        title: string;
        status: 'open' | 'fixed' | 'closed';
        note: string | null;
        at: string;
    }[];
};

const STATUS: Record<Props['mine'][number]['status'], string> = {
    open: 'We have it',
    fixed: 'Fixed',
    closed: 'Closed',
};

/** Tell us about a bug, with a photo or a short video of it. */
export default function ReportBugPage({ from, maxMb, mine }: Props) {
    const form = useForm<{
        title: string;
        description: string;
        page: string;
        attachment: File | null;
    }>({ title: '', description: '', page: from, attachment: null });

    return (
        <>
            <Head title="Report a bug" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
                <GameBar showNextStep={false} />
                <header>
                    <h1 className="flex items-center gap-2 text-2xl font-extrabold tracking-tight">
                        <Bug className="size-6" aria-hidden /> Report a bug
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Something broken, stuck or wrong? Tell us what you did
                        and what happened. A screenshot or a short screen
                        recording helps us find it fastest. We see your animal
                        with the report, so we can look at it as you see it.
                    </p>
                </header>

                <form
                    className="flex flex-col gap-4 rounded-xl border bg-card p-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(store.url(), {
                            forceFormData: true,
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="title">What's wrong, in a line</Label>
                        <Input
                            id="title"
                            maxLength={120}
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                            placeholder="The Fight button does nothing"
                            required
                        />
                        <InputError message={form.errors.title} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="description">
                            What did you do, and what happened?
                        </Label>
                        <Textarea
                            id="description"
                            rows={6}
                            maxLength={5000}
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                            placeholder="I opened the map, tapped the plunderers banner, picked 2 horseshoes and pressed Fight them. Nothing happened and my horseshoes stayed the same."
                            required
                        />
                        <InputError message={form.errors.description} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="page">
                            Where in the game (optional)
                        </Label>
                        <Input
                            id="page"
                            maxLength={255}
                            value={form.data.page}
                            onChange={(e) =>
                                form.setData('page', e.target.value)
                            }
                            placeholder="The map, the Faction page…"
                        />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="attachment">
                            A photo or video (optional, up to {maxMb} MB)
                        </Label>
                        <Input
                            id="attachment"
                            type="file"
                            accept="image/*,video/mp4,video/quicktime,video/webm"
                            onChange={(e) =>
                                form.setData(
                                    'attachment',
                                    e.target.files?.[0] ?? null,
                                )
                            }
                        />
                        <InputError message={form.errors.attachment} />
                        {form.progress && (
                            <progress
                                value={form.progress.percentage}
                                max={100}
                                className="h-2 w-full"
                            />
                        )}
                    </div>
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Sending…' : 'Send the report'}
                        </Button>
                    </div>
                </form>

                {mine.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <h2 className="text-lg font-bold">Your reports</h2>
                        <ul className="divide-y rounded-xl border">
                            {mine.map((report) => (
                                <li
                                    key={report.id}
                                    className="flex flex-col gap-1 px-4 py-3 text-sm"
                                >
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span className="font-semibold">
                                            #{report.id} {report.title}
                                        </span>
                                        <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-semibold">
                                            {STATUS[report.status]}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {shortWhen(report.at)}
                                        </span>
                                    </span>
                                    {report.note && (
                                        <span className="text-muted-foreground">
                                            {report.note}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}
