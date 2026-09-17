/**
 * Fetch-on-mount with loading, error and reload, cancelled cleanly on unmount.
 *
 * The abort matters here more than usual: a receptionist clicking through days
 * faster than the network answers would otherwise get the FIRST response
 * painted last, and the screen would show a day they have already left.
 */

import { useCallback, useEffect, useState } from 'react'
import { ApiError } from '../services/api'

export interface AsyncState<T> {
  data: T | null
  loading: boolean
  error: ApiError | Error | null
  reload: () => void
}

export function useApi<T>(
  fetcher: (signal: AbortSignal) => Promise<T>,
  deps: unknown[],
  enabled = true,
): AsyncState<T> {
  const [data, setData] = useState<T | null>(null)
  const [loading, setLoading] = useState(enabled)
  const [error, setError] = useState<ApiError | Error | null>(null)
  const [token, setToken] = useState(0)

  const reload = useCallback(() => setToken((n) => n + 1), [])

  useEffect(() => {
    if (!enabled) {
      setLoading(false)
      return
    }

    const controller = new AbortController()
    let cancelled = false

    setLoading(true)
    setError(null)

    fetcher(controller.signal)
      .then((result) => {
        if (!cancelled) setData(result)
      })
      .catch((err: Error) => {
        // An abort is this component going away, not a failure to report.
        if (cancelled || controller.signal.aborted) return
        setError(err)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
      controller.abort()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, token, enabled])

  return { data, loading, error, reload }
}

/**
 * The same thing, refreshed on a timer.
 *
 * For Live Operations, which is open all day on a screen behind a desk. The
 * interval comes from the SERVER (`refresh_seconds`) rather than a constant in
 * a component, so the cadence is a decision somebody can change in one place.
 *
 * Refreshing pauses while the tab is hidden. A front-desk screen left open
 * overnight should not spend the night polling.
 */
export function usePolledApi<T>(
  fetcher: (signal: AbortSignal) => Promise<T>,
  deps: unknown[],
  intervalSeconds: number,
  enabled = true,
): AsyncState<T> & { paused: boolean } {
  const state = useApi(fetcher, deps, enabled)
  const [paused, setPaused] = useState(() => typeof document !== 'undefined' && document.hidden)

  useEffect(() => {
    const onVisibility = () => setPaused(document.hidden)
    document.addEventListener('visibilitychange', onVisibility)
    return () => document.removeEventListener('visibilitychange', onVisibility)
  }, [])

  const { reload } = state

  useEffect(() => {
    if (!enabled || paused || intervalSeconds <= 0) return

    const timer = window.setInterval(reload, intervalSeconds * 1000)
    return () => window.clearInterval(timer)
  }, [enabled, paused, intervalSeconds, reload])

  // Coming back to a paused tab should show current figures immediately rather
  // than whatever was on screen when the user switched away.
  useEffect(() => {
    if (!paused && enabled) reload()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [paused])

  return { ...state, paused }
}
