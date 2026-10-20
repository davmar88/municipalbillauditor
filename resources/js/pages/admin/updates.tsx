import { Head, useForm } from '@inertiajs/react';
import { Megaphone, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { act } from '@/lib/game';
import { destroy, store } from '@/routes/admin/updates';

type Update = {
    id: number;
    on: string;
    kind: 'new' | 'fix';
    title: string;
    body: string;
};

type Props = { updates: Update[]; publicUrl: string };

/** The owner's side of the public updates page: post what's new or fixed. */
export default function UpdatesPage({ updates, publicUrl }: Props) {
    const form = useForm({ kind: 'new', title: '', body: '' });

    return (
        <>
            <Head title="Updates and fixes" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
                <header>
                    <h1 className="flex items-center gap-2 text-2xl font-extrabold tracking-tight">
                        <Megaphone className="size-6" aria-hidden /> Updates and
                        fixes
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        What you post here shows on the public{' '}
                        <a
                            href={publicUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="underline underline-offset-2"
                        >
                            updates page
                        </a>
                        , dated today.
                    </p>
                </header>

                <form
                    className="flex flex-col gap-3 rounded-xl border bg-card p-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(store.url(), {
                            preserveScroll: true,
                            onSuccess: () => form.reset('title', 'body'),
                        });
                    }}
                >
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="kind">Kind</Label>
                        <NativeSelect
                            id="kind"
                            value={form.data.kind}
                            onChange={(e) =>
                                form.setData('kind', e.target.value)
                            }
                        >
                            <option value="new">New</option>
                            <option value="fix">Fixed</option>
                        </NativeSelect>
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="title">Title</Label>
                        <Input
                            id="title"
                            maxLength={120}
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                            required
                        />
                        <InputError message={form.errors.title} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="body">What changed</Label>
                        <Textarea
                            id="body"
                            rows={3}
                            maxLength={2000}
                            value={form.data.body}
                            onChange={(e) =>
                                form.setData('body', e.target.value)
                            }
                            required
                        />
                        <InputError message={form.errors.body} />
                    </div>
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            Post it
                        </Button>
                    </div>
                </form>

                <ul className="flex flex-col gap-2">
                    {updates.map((update) => (
                        <li
                            key={update.id}
                            className="flex items-start gap-3 rounded-xl border bg-card p-3 text-sm"
                        >
                            <span className="min-w-0 flex-1">
                                <span className="block font-semibold">
                                    <span className="mr-2 text-xs text-muted-foreground">
                                        {update.on} ·{' '}
                                        {update.kind === 'fix'
                                            ? 'Fixed'
                                            : 'New'}
                                    </span>
                                    {update.title}
                                </span>
                                <span className="text-muted-foreground">
                                    {update.body}
                                </span>
                            </span>
                            <Button
                                size="icon"
                                variant="ghost"
                                aria-label={`Remove ${update.title}`}
                                onClick={() =>
                                    act(destroy.url(update.id), {}, 'delete')
                                }
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}
