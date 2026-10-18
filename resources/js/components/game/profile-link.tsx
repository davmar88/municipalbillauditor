import { Link } from '@inertiajs/react';
import type { CSSProperties, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { animal as profile } from '@/routes/play';

/** An animal's name that opens its profile: its stats, shop and collection. */
export function ProfileLink({
    animal,
    className,
    style,
    onClick,
    children,
}: {
    animal: { id: number; name: string };
    className?: string;
    style?: CSSProperties;
    onClick?: () => void;
    children?: ReactNode;
}) {
    return (
        <Link
            href={profile(animal.id)}
            className={cn('no-underline hover:underline', className)}
            style={style}
            onClick={onClick}
            title={`See ${animal.name}'s profile`}
        >
            {children ?? animal.name}
        </Link>
    );
}
