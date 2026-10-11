import type { SVGAttributes } from 'react';

/**
 * A bill with an orange check mark circled on it. The circle is filled with
 * the surface colour so the bill's corner doesn't show through.
 */
export default function AppLogoIcon({
    ground = 'var(--sidebar)',
    ...props
}: SVGAttributes<SVGElement> & { ground?: string }) {
    return (
        <svg
            viewBox="0 0 28 28"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
            {...props}
        >
            <rect
                x="5"
                y="3"
                width="16"
                height="22"
                rx="2"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
            />
            <path
                d="M9 9h8M9 13h5"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
            />
            <circle
                cx="18.5"
                cy="19.5"
                r="5.5"
                fill={ground}
                stroke="#F07A3E"
                strokeWidth="2.2"
            />
        </svg>
    );
}
