import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { BannerData } from '@/components/game/faction-banner';
import { FactionBanner } from '@/components/game/faction-banner';
import { Button } from '@/components/ui/button';
import { act } from '@/lib/game';
import { banner as design } from '@/routes/play/faction';
import { takeDown, upload } from '@/routes/play/faction/banner';

const PATTERNS: [string, string][] = [
    ['plain', 'Plain'],
    ['stripes', 'Stripes'],
    ['chevron', 'Chevron'],
    ['cross', 'Cross'],
    ['saltire', 'Saltire'],
    ['quarters', 'Quarters'],
    ['bend', 'Bend'],
    ['border', 'Border'],
    ['chief', 'Chief'],
];

const EMBLEMS = [
    '',
    '🐷',
    '🐴',
    '🐶',
    '🐑',
    '🐐',
    '🐔',
    '🪶',
    '⚔️',
    '🛡️',
    '🌾',
    '🍎',
    '🔥',
    '⭐',
    '🌙',
    '☀️',
    '👑',
    '🐺',
    '⚒️',
    '🌳',
];

/** The leader's banner workshop: a pattern in two colours with an emblem, or an uploaded picture. */
export function BannerEditor({
    banner,
    name,
}: {
    banner: BannerData;
    name: string;
}) {
    const [pattern, setPattern] = useState(banner.pattern);
    const [field, setField] = useState(banner.colours[0]);
    const [charge, setCharge] = useState(banner.colours[1]);
    const [emblem, setEmblem] = useState(banner.emblem);
    const [uploading, setUploading] = useState(false);
    const preview: BannerData = {
        mode: 'pattern',
        pattern,
        colours: [field, charge],
        emblem,
        image: null,
    };

    return (
        <section className="flex flex-col gap-3 rounded-xl border bg-card p-4">
            <div>
                <h2 className="text-lg font-bold">Your banner</h2>
                <p className="text-sm text-muted-foreground">
                    Design a banner from a pattern, or upload a picture of your
                    own. It flies on your faction's page and in the valley's
                    tables. Only the leader and council can change it.
                </p>
            </div>
            <div className="flex flex-wrap items-start gap-6">
                <div className="flex flex-col items-center gap-1">
                    <FactionBanner banner={preview} name={name} size={120} />
                    <span className="text-xs text-muted-foreground">
                        Pattern
                    </span>
                </div>
                {banner.mode === 'image' && banner.image && (
                    <div className="flex flex-col items-center gap-1">
                        <FactionBanner banner={banner} name={name} size={120} />
                        <span className="text-xs text-muted-foreground">
                            Flying now
                        </span>
                    </div>
                )}
                <div className="flex min-w-[220px] flex-1 flex-col gap-3 text-sm">
                    <div
                        className="flex flex-wrap gap-1.5"
                        role="radiogroup"
                        aria-label="Pattern"
                    >
                        {PATTERNS.map(([value, label]) => (
                            <button
                                key={value}
                                type="button"
                                role="radio"
                                aria-checked={pattern === value}
                                onClick={() => setPattern(value)}
                                className={`flex flex-col items-center gap-1 rounded-lg border p-1.5 text-[11px] ${pattern === value ? 'border-primary bg-primary/10' : ''}`}
                            >
                                <FactionBanner
                                    banner={{
                                        ...preview,
                                        pattern: value,
                                        emblem: '',
                                    }}
                                    name={label}
                                    size={36}
                                />
                                {label}
                            </button>
                        ))}
                    </div>
                    <div className="flex flex-wrap gap-4">
                        <label className="flex items-center gap-2">
                            Cloth
                            <input
                                type="color"
                                value={field}
                                onChange={(e) => setField(e.target.value)}
                                className="h-8 w-12 rounded border"
                            />
                        </label>
                        <label className="flex items-center gap-2">
                            Pattern
                            <input
                                type="color"
                                value={charge}
                                onChange={(e) => setCharge(e.target.value)}
                                className="h-8 w-12 rounded border"
                            />
                        </label>
                    </div>
                    <div
                        className="flex flex-wrap gap-1"
                        role="radiogroup"
                        aria-label="Emblem"
                    >
                        {EMBLEMS.map((value) => (
                            <button
                                key={value || 'none'}
                                type="button"
                                role="radio"
                                aria-checked={emblem === value}
                                onClick={() => setEmblem(value)}
                                className={`size-9 rounded-md border text-lg ${emblem === value ? 'border-primary bg-primary/10' : ''}`}
                            >
                                {value || (
                                    <span className="text-xs text-muted-foreground">
                                        none
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>
                    <div>
                        <Button
                            size="sm"
                            onClick={() =>
                                act(
                                    design.url(),
                                    {
                                        pattern,
                                        colours: [field, charge],
                                        emblem,
                                    },
                                    'put',
                                )
                            }
                        >
                            Fly this banner
                        </Button>
                    </div>
                    <div className="flex flex-col gap-1.5 border-t pt-3">
                        <span className="font-semibold">
                            Or upload a picture
                        </span>
                        <span className="text-xs text-muted-foreground">
                            PNG, WebP or JPG, up to 1 MB, between 64 and 2048
                            pixels. A tall picture fits best. Keep it clean: our
                            team takes down anything offensive.
                        </span>
                        <input
                            type="file"
                            accept="image/png,image/webp,image/jpeg"
                            disabled={uploading}
                            onChange={(e) => {
                                const file = e.target.files?.[0];
                                if (!file) {
                                    return;
                                }
                                setUploading(true);
                                router.post(
                                    upload.url(),
                                    { image: file },
                                    {
                                        forceFormData: true,
                                        preserveScroll: true,
                                        onFinish: () => setUploading(false),
                                    },
                                );
                            }}
                        />
                        {banner.image && (
                            <div>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        act(takeDown.url(), {}, 'delete')
                                    }
                                >
                                    Take the picture down
                                </Button>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </section>
    );
}
