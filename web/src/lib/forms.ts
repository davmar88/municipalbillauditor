import { isValidationError } from '../api'

/** The first message per field from a 422, or an empty object. */
export function fieldErrors(error: unknown): Record<string, string> {
  if (!isValidationError(error)) return {}
  const result: Record<string, string> = {}
  for (const [field, messages] of Object.entries(error.errors)) {
    if (messages.length > 0) result[field] = messages[0]
  }
  return result
}

/** Merges client-side and server-side errors; client errors win. */
export function mergeErrors(
  client: Record<string, string>,
  serverError: unknown,
): Record<string, string> {
  return { ...fieldErrors(serverError), ...client }
}
