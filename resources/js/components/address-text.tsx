/**
 * An email address that, when it has to wrap, wraps before the "@" rather
 * than mid-word.
 */
export function AddressText({ address }: { address: string }) {
    const at = address.indexOf('@');

    if (at < 0) {
        return <>{address}</>;
    }

    return (
        <>
            {address.slice(0, at)}
            <wbr />
            {address.slice(at)}
        </>
    );
}
