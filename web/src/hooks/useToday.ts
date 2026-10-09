import { useState } from 'react'

/** Today's date, fixed for the lifetime of the component. */
export function useToday(): Date {
  const [today] = useState(() => new Date())
  return today
}
