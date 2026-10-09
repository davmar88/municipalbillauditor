import { ApiError } from '@/api';

/** First error message per field, e.g. { email: 'The email has already been taken.' }. */
export type FieldErrors = Record<string, string>;

export function fieldErrorsFrom(error: unknown): FieldErrors {
  if (!(error instanceof ApiError) || error.status !== 422) return {};
  const result: FieldErrors = {};
  for (const [field, messages] of Object.entries(error.errors)) {
    if (Array.isArray(messages) && messages.length > 0) result[field] = String(messages[0]);
  }
  return result;
}

export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) return error.message;
  if (error instanceof Error && error.message) return error.message;
  return 'Something went wrong. Please try again.';
}

/** Messages for fields the form doesn't display itself, so they can be shown in a summary. */
export function otherFieldMessages(errors: FieldErrors, shownFields: readonly string[]): string[] {
  return Object.entries(errors)
    .filter(([field]) => !shownFields.some((shown) => field === shown || field.startsWith(`${shown}.`)))
    .map(([, message]) => message);
}
