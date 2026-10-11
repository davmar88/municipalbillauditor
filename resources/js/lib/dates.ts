/** Today's date in the browser's own timezone, as YYYY-MM-DD. */
export function localToday(): string {
    const now = new Date();
    const offset = now.getTimezoneOffset() * 60_000;

    return new Date(now.getTime() - offset).toISOString().slice(0, 10);
}
