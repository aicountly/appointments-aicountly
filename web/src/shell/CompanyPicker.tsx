/**
 * The company switcher.
 *
 * Live from Manage — this product does not keep a company list. Branches come
 * from the same call, which is why picking a company resets the branch: a
 * branch id means nothing in a different company.
 */

import { useEffect, useRef, useState } from 'react'
import { Building2, Check, ChevronDown } from 'lucide-react'
import { useAppointments } from '../context/AppointmentsContext'
import { api } from '../services/api'

interface Branch {
  bo_id: number
  name: string
}

export function CompanyPicker() {
  const { companies, scope, selectCompany, selectBranch } = useAppointments()
  const [open, setOpen] = useState(false)
  const [branches, setBranches] = useState<Branch[]>([])
  const containerRef = useRef<HTMLDivElement>(null)

  const current = companies.find((company) => company.cmp_id === scope?.cmp_id)

  useEffect(() => {
    if (!open) return

    const onClickOutside = (event: MouseEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false)
    }
    const onEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false)
    }

    document.addEventListener('mousedown', onClickOutside)
    document.addEventListener('keydown', onEscape)
    return () => {
      document.removeEventListener('mousedown', onClickOutside)
      document.removeEventListener('keydown', onEscape)
    }
  }, [open])

  // Branches are read when the menu opens rather than on every company change:
  // most people never switch branch, and Manage should not be asked on a page
  // load for a list nobody opened.
  useEffect(() => {
    if (!open || scope === null) return

    const controller = new AbortController()

    api
      .unscoped<unknown>('v1/manage/companyinfo', { cmp_id: scope.cmp_id }, controller.signal)
      .then((payload) => {
        if (controller.signal.aborted) return
        setBranches(extractBranches(payload))
      })
      .catch(() => {
        // A branch list that will not load leaves the company switcher working,
        // which is the part that matters.
        if (!controller.signal.aborted) setBranches([])
      })

    return () => controller.abort()
  }, [open, scope?.cmp_id])

  if (companies.length === 0) return null

  const currentBranch = branches.find((branch) => branch.bo_id === scope?.bo_id)

  return (
    <div ref={containerRef} style={{ position: 'relative' }}>
      <button
        type="button"
        className="appt-button appt-button-small"
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        aria-haspopup="menu"
        style={{ maxWidth: 220 }}
      >
        <Building2 size={14} aria-hidden />
        <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
          {current?.name ?? 'Choose a company'}
          {currentBranch && ` · ${currentBranch.name}`}
        </span>
        <ChevronDown size={14} aria-hidden />
      </button>

      {open && (
        <div
          role="menu"
          style={{
            position: 'absolute',
            right: 0,
            top: 'calc(100% + 6px)',
            zIndex: 70,
            minWidth: 260,
            maxHeight: 420,
            overflowY: 'auto',
            background: 'var(--surface)',
            border: '1px solid var(--border)',
            borderRadius: 12,
            boxShadow: 'var(--shadow-lg)',
            padding: 6,
          }}
        >
          <p style={{ margin: '6px 10px', fontSize: 11, fontWeight: 700, color: 'var(--muted)', textTransform: 'uppercase' }}>
            Company
          </p>

          {companies.map((company) => (
            <button
              key={company.cmp_id}
              type="button"
              role="menuitem"
              onClick={() => {
                selectCompany(company.cmp_id)
                setOpen(false)
              }}
              style={menuItemStyle(company.cmp_id === scope?.cmp_id)}
            >
              <span style={{ flex: 1, textAlign: 'left' }}>{company.name}</span>
              {company.cmp_id === scope?.cmp_id && <Check size={14} aria-hidden />}
            </button>
          ))}

          {branches.length > 0 && (
            <>
              <div style={{ height: 1, background: 'var(--border)', margin: '6px 4px' }} />
              <p style={{ margin: '6px 10px', fontSize: 11, fontWeight: 700, color: 'var(--muted)', textTransform: 'uppercase' }}>
                Location
              </p>

              <button
                type="button"
                role="menuitem"
                onClick={() => {
                  selectBranch(0)
                  setOpen(false)
                }}
                style={menuItemStyle(scope?.bo_id === 0)}
              >
                <span style={{ flex: 1, textAlign: 'left' }}>All locations</span>
                {scope?.bo_id === 0 && <Check size={14} aria-hidden />}
              </button>

              {branches.map((branch) => (
                <button
                  key={branch.bo_id}
                  type="button"
                  role="menuitem"
                  onClick={() => {
                    selectBranch(branch.bo_id)
                    setOpen(false)
                  }}
                  style={menuItemStyle(branch.bo_id === scope?.bo_id)}
                >
                  <span style={{ flex: 1, textAlign: 'left' }}>{branch.name}</span>
                  {branch.bo_id === scope?.bo_id && <Check size={14} aria-hidden />}
                </button>
              ))}
            </>
          )}
        </div>
      )}
    </div>
  )
}

function menuItemStyle(active: boolean): React.CSSProperties {
  return {
    display: 'flex',
    alignItems: 'center',
    gap: 8,
    width: '100%',
    minHeight: 38,
    padding: '0 10px',
    border: 0,
    borderRadius: 9,
    background: active ? 'var(--brand-soft)' : 'transparent',
    color: active ? 'var(--accent)' : 'var(--fg)',
    fontWeight: active ? 650 : 500,
    cursor: 'pointer',
  }
}

/** Manage's branch list, whatever shape it arrived in. */
function extractBranches(payload: unknown): Branch[] {
  const body = (payload as { data?: unknown })?.data ?? payload
  if (typeof body !== 'object' || body === null) return []

  const record = body as Record<string, unknown>
  const rows = Array.isArray(record.branches) ? record.branches : Array.isArray(record.bo) ? record.bo : []

  const out: Branch[] = []

  for (const row of rows) {
    if (typeof row !== 'object' || row === null) continue
    const branch = row as Record<string, unknown>
    const id = Number(branch.bo_id ?? branch.id ?? 0)
    if (!Number.isFinite(id) || id <= 0) continue

    out.push({
      bo_id: id,
      name: String(branch.bo_name ?? branch.name ?? `Location ${id}`),
    })
  }

  return out
}
