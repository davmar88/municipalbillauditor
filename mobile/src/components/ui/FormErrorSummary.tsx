import { ApiError } from '@/api';
import { otherFieldMessages, type FieldErrors } from '@/lib/forms';

import { AppText } from './AppText';
import { Notice } from './Notice';

/**
 * Form-level error. For 422s it tells the person to look at the highlighted fields and lists
 * any messages for fields this form doesn't show.
 */
export function FormErrorSummary({
  error,
  fieldErrors,
  shownFields,
  message,
}: {
  error?: unknown;
  fieldErrors?: FieldErrors;
  shownFields?: readonly string[];
  message?: string | null;
}) {
  if (message) return <Notice tone="danger">{message}</Notice>;
  if (!error) return null;
  if (error instanceof ApiError && error.status === 422) {
    const extra = otherFieldMessages(fieldErrors ?? {}, shownFields ?? []);
    const hasShown = Object.keys(fieldErrors ?? {}).length > extra.length;
    return (
      <Notice tone="danger" title={hasShown ? 'Please check the highlighted fields.' : undefined}>
        {extra.length > 0 ? (
          extra.map((text) => <AppText key={text}>{text}</AppText>)
        ) : !hasShown ? (
          <AppText>{error.message}</AppText>
        ) : null}
      </Notice>
    );
  }
  return (
    <Notice tone="danger">{error instanceof Error ? error.message : 'Something went wrong. Please try again.'}</Notice>
  );
}
