/**
 * The company scope and the session, resolved once and read everywhere.
 *
 * ## Why the company comes first
 *
 * Every scoped endpoint needs `cmp_id`, and the tenant check on the backend
 * refuses a company this session cannot open. So the app cannot render a single
 * panel until a company is chosen — and choosing one is itself a live read from
 * Manage, because the list of companies somebody may open is Manage's answer,
 * not this product's.
 *
 * ## `can()` is a courtesy
 *
 * Permissions are enforced in the backend, on every route, before the query.
 * Hiding a nav item here stops somebody wasting a click; it does not stop
 * anybody doing anything, and nothing in this file should ever be the only
 * thing between a user and an action.
 */

import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, ApiError, setScope, type CompanyScope } from '../services/api'
import type { SessionResponse } from '../services/types'

const COMPANY_STORAGE_KEY = 'appointments:company'

interface CompanyOption {
  cmp_id: number
  name: string
}

interface AppointmentsContextValue {
  session: SessionResponse | null
  scope: CompanyScope | null
  companies: CompanyOption[]
  companiesError: string | null
  loading: boolean
  error: ApiError | Error | null
  can: (permission: string) => boolean
  feature: (flag: string) => { enabled: boolean; reason: string | null }
  timezone: string
  currency: string
  selectCompany: (cmpId: number) => void
  selectBranch: (boId: number) => void
  reload: () => void
}

const AppointmentsContext = createContext<AppointmentsContextValue | null>(null)

function readStoredCompany(): number | null {
  try {
    const raw = window.localStorage.getItem(COMPANY_STORAGE_KEY)
    const parsed = raw ? Number(raw) : NaN
    return Number.isFinite(parsed) && parsed > 0 ? parsed : null
  } catch {
    return null
  }
}

function storeCompany(cmpId: number): void {
  try {
    window.localStorage.setItem(COMPANY_STORAGE_KEY, String(cmpId))
  } catch {
    /* private mode, quota — the app still works, it just forgets */
  }
}

export function AppointmentsProvider({ children }: { children: ReactNode }) {
  const [companies, setCompanies] = useState<CompanyOption[]>([])
  const [companiesError, setCompaniesError] = useState<string | null>(null)
  const [cmpId, setCmpId] = useState<number | null>(readStoredCompany)
  const [boId, setBoId] = useState(0)
  const [session, setSession] = useState<SessionResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<ApiError | Error | null>(null)
  const [token, setToken] = useState(0)

  const reload = useCallback(() => setToken((n) => n + 1), [])

  // --- The companies this session may open, from Manage -------------------
  useEffect(() => {
    const controller = new AbortController()

    api
      .unscoped<unknown>('v1/manage/companies', undefined, controller.signal)
      .then((payload) => {
        if (controller.signal.aborted) return

        const rows = extractCompanies(payload)
        setCompanies(rows)
        setCompaniesError(null)

        // Keep the remembered company only if it is still one this session may
        // open — a person removed from a company should not land back in it.
        setCmpId((current) => {
          if (current && rows.some((row) => row.cmp_id === current)) return current
          return rows[0]?.cmp_id ?? null
        })
      })
      .catch((err: Error) => {
        if (controller.signal.aborted) return
        setCompaniesError(err.message)
        // A remembered company still lets the app work while Manage is having a
        // bad minute — the backend re-checks access on every request anyway.
        if (readStoredCompany() === null) setLoading(false)
      })

    return () => controller.abort()
  }, [token])

  // --- The session, once a company is chosen ------------------------------
  useEffect(() => {
    if (cmpId === null) {
      setLoading(companiesError === null)
      return
    }

    setScope({ cmp_id: cmpId, bo_id: boId })
    storeCompany(cmpId)

    const controller = new AbortController()
    setLoading(true)
    setError(null)

    api
      .unscoped<{ data: SessionResponse }>('v1/session', { cmp_id: cmpId, bo_id: boId }, controller.signal)
      .then((payload) => {
        if (!controller.signal.aborted) setSession(payload.data)
      })
      .catch((err: Error) => {
        if (!controller.signal.aborted) setError(err)
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false)
      })

    return () => controller.abort()
  }, [cmpId, boId, token, companiesError])

  const permissions = useMemo(() => new Set(session?.permissions ?? []), [session])

  const can = useCallback(
    (permission: string) => {
      if (session === null) return false
      if (session.user.is_owner) return true
      return permissions.has(permission)
    },
    [session, permissions],
  )

  const feature = useCallback(
    (flag: string) => session?.features?.[flag.toLowerCase()] ?? { enabled: false, reason: null },
    [session],
  )

  const selectCompany = useCallback((next: number) => {
    setCmpId(next)
    // A branch chosen in one company means nothing in another.
    setBoId(0)
    setSession(null)
  }, [])

  const value = useMemo<AppointmentsContextValue>(
    () => ({
      session,
      scope: cmpId === null ? null : { cmp_id: cmpId, bo_id: boId },
      companies,
      companiesError,
      loading,
      error,
      can,
      feature,
      timezone: session?.settings.timezone ?? 'Asia/Kolkata',
      currency: session?.settings.currency ?? 'INR',
      selectCompany,
      selectBranch: setBoId,
      reload,
    }),
    [session, cmpId, boId, companies, companiesError, loading, error, can, feature, selectCompany, reload],
  )

  return <AppointmentsContext.Provider value={value}>{children}</AppointmentsContext.Provider>
}

export function useAppointments(): AppointmentsContextValue {
  const context = useContext(AppointmentsContext)
  if (context === null) {
    throw new Error('useAppointments must be used inside AppointmentsProvider')
  }
  return context
}

/**
 * Manage's company list, whatever shape it arrived in.
 *
 * Tolerant on purpose: this is another product's response and the one thing
 * that must not happen is the whole app refusing to start because a field was
 * named `comp_id` instead of `cmp_id`.
 */
function extractCompanies(payload: unknown): CompanyOption[] {
  const rows = Array.isArray(payload)
    ? payload
    : Array.isArray((payload as { data?: unknown })?.data)
      ? ((payload as { data: unknown[] }).data)
      : Array.isArray((payload as { companies?: unknown })?.companies)
        ? ((payload as { companies: unknown[] }).companies)
        : []

  const out: CompanyOption[] = []

  for (const row of rows) {
    if (typeof row !== 'object' || row === null) continue
    const record = row as Record<string, unknown>
    const id = Number(record.cmp_id ?? record.comp_id ?? record.id ?? 0)
    if (!Number.isFinite(id) || id <= 0) continue

    out.push({
      cmp_id: id,
      name: String(record.cmp_name ?? record.company_name ?? record.name ?? `Company ${id}`),
    })
  }

  return out
}
