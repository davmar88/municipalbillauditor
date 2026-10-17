import type { SVGAttributes } from 'react';

/** A barn under a crown: who holds the Farmhouse rules the valley. */
export default function AppLogoIcon({
    ground: _ground,
    ...props
}: SVGAttributes<SVGElement> & { ground?: string }) {
    return (
        <svg
            viewBox="0 0 28 28"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
            {...props}
        >
            <path d="M8 7l2.5 2.5L14 5l3.5 4.5L20 7v4H8z" fill="currentColor" />
            <path
                d="M5 25V15l9-4 9 4v10z"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinejoin="round"
            />
            <path
                d="M11 25v-6h6v6"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinejoin="round"
            />
        </svg>
    );
}
