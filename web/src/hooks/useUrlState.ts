/**
 * Filters and the selected tab, kept in the query string.
 *
 * A dashboard somebody cannot send to a colleague is a dashboard they
 * screenshot instead, and a screenshot of a figure is how a stale number
 * outlives the thing it described. Keeping state in the URL also makes the
 * browser's Back button behave, which on a filtered list is the difference
 * between a tool and an obstacle.
 */

import { useCallback } from 'react'
import { useSearchParams } from 'react-router-dom'

export function useUrlState(): {
  params: URLSearchParams
  get: (key: string, fallback?: string) => string
  set: (key: string, value: string | null) => void
  setMany: (values: Record<string, string | null>) => void
} {
  const [params, setParams] = useSearchParams()

  const get = useCallback(
    (key: string, fallback = '') => params.get(key) ?? fallback,
    [params],
  )

  const set = useCallback(
    (key: string, value: string | null) => {
      const next = new URLSearchParams(params)
      if (value === null || value === '') next.delete(key)
      else next.set(key, value)
      // replace, not push: changing a filter should not put an entry in history
      // for every keystroke.
      setParams(next, { replace: true })
    },
    [params, setParams],
  )

  const setMany = useCallback(
    (values: Record<string, string | null>) => {
      const next = new URLSearchParams(params)
      for (const [key, value] of Object.entries(values)) {
        if (value === null || value === '') next.delete(key)
        else next.set(key, value)
      }
      setParams(next, { replace: true })
    },
    [params, setParams],
  )

  return { params, get, set, setMany }
}
