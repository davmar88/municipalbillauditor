import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'

// jsdom doesn't implement scrolling; the layout scrolls to the top on navigation.
window.scrollTo = () => {}

afterEach(() => {
  cleanup()
  window.localStorage.clear()
})
