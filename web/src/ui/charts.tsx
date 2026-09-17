/**
 * Charts, drawn as inline SVG.
 *
 * ## No chart library
 *
 * Four chart types, each under a hundred lines, against 200KB of library and a
 * second set of theming rules to keep in step with the design system. The
 * accessibility story is also better this way: each of these carries a real
 * text alternative rather than a canvas nobody can read.
 *
 * ## The rules they share
 *
 * - They resize. Every one is a viewBox with `width: 100%`, so the container
 *   decides and nothing overflows.
 * - They have a legend, and the legend is text, not a colour somebody has to
 *   match by eye.
 * - They render loading, empty and error somewhere else — PanelState does that,
 *   and a chart that draws its own "no data" ends up saying it differently from
 *   the panel beside it.
 * - Colour is never the only signal: the donut labels its slices, the line
 *   chart lists its series, the funnel prints its numbers.
 */

import { useId, useMemo, useState } from 'react'

/** Chart ink, in the AICOUNTLY palette. Green leads; the rest are distinguishable. */
export const SERIES_COLOURS = [
  '#25b003',
  '#2563eb',
  '#7c3aed',
  '#f59e0b',
  '#0891b2',
  '#be185d',
  '#65a30d',
  '#6b7280',
] as const

// ---------------------------------------------------------------------------
// Line chart
// ---------------------------------------------------------------------------

export interface LineSeries {
  key: string
  label: string
  colour?: string
}

export function LineChart({
  points,
  series,
  height = 240,
  formatX,
}: {
  points: Record<string, number | string>[]
  series: LineSeries[]
  height?: number
  formatX?: (value: string) => string
}) {
  const titleId = useId()
  const [hover, setHover] = useState<number | null>(null)

  const width = 760
  const padding = { top: 16, right: 16, bottom: 32, left: 40 }
  const plotWidth = width - padding.left - padding.right
  const plotHeight = height - padding.top - padding.bottom

  const max = useMemo(() => {
    let highest = 0
    for (const point of points) {
      for (const line of series) {
        highest = Math.max(highest, Number(point[line.key] ?? 0))
      }
    }
    // A flat zero series should not divide by zero, and a chart whose ceiling
    // is exactly its peak clips the top marker.
    return highest === 0 ? 1 : highest * 1.1
  }, [points, series])

  const x = (index: number) =>
    padding.left + (points.length <= 1 ? plotWidth / 2 : (index / (points.length - 1)) * plotWidth)
  const y = (value: number) => padding.top + plotHeight - (value / max) * plotHeight

  const ticks = [0, 0.25, 0.5, 0.75, 1].map((fraction) => Math.round(max * fraction))

  return (
    <div>
      <svg
        viewBox={`0 0 ${width} ${height}`}
        style={{ width: '100%', height: 'auto', display: 'block' }}
        role="img"
        aria-labelledby={titleId}
        onMouseLeave={() => setHover(null)}
      >
        <title id={titleId}>
          {series.map((line) => line.label).join(', ')} over {points.length} periods
        </title>

        {ticks.map((tick) => (
          <g key={tick}>
            <line
              x1={padding.left}
              x2={width - padding.right}
              y1={y(tick)}
              y2={y(tick)}
              stroke="#e0e8e2"
              strokeWidth={1}
            />
            <text x={padding.left - 8} y={y(tick) + 4} textAnchor="end" fontSize={10} fill="#596b62">
              {tick}
            </text>
          </g>
        ))}

        {series.map((line, lineIndex) => {
          const colour = line.colour ?? SERIES_COLOURS[lineIndex % SERIES_COLOURS.length]
          const path = points
            .map((point, index) => `${index === 0 ? 'M' : 'L'} ${x(index)} ${y(Number(point[line.key] ?? 0))}`)
            .join(' ')

          return (
            <g key={line.key}>
              <path d={path} fill="none" stroke={colour} strokeWidth={2} strokeLinejoin="round" />
              {hover !== null && points[hover] && (
                <circle cx={x(hover)} cy={y(Number(points[hover][line.key] ?? 0))} r={4} fill={colour} />
              )}
            </g>
          )
        })}

        {points.map((point, index) => (
          <rect
            key={index}
            x={x(index) - plotWidth / Math.max(1, points.length) / 2}
            y={padding.top}
            width={plotWidth / Math.max(1, points.length)}
            height={plotHeight}
            fill="transparent"
            onMouseEnter={() => setHover(index)}
          >
            <title>
              {formatX ? formatX(String(point.at)) : String(point.at)}
              {series.map((line) => ` · ${line.label}: ${point[line.key] ?? 0}`).join('')}
            </title>
          </rect>
        ))}

        {hover !== null && (
          <line
            x1={x(hover)}
            x2={x(hover)}
            y1={padding.top}
            y2={padding.top + plotHeight}
            stroke="#c9d7ce"
            strokeDasharray="3 3"
          />
        )}

        {points.map((point, index) =>
          index % Math.max(1, Math.ceil(points.length / 7)) === 0 ? (
            <text
              key={`label-${index}`}
              x={x(index)}
              y={height - 10}
              textAnchor="middle"
              fontSize={10}
              fill="#596b62"
            >
              {formatX ? formatX(String(point.at)) : String(point.at)}
            </text>
          ) : null,
        )}
      </svg>

      <div className="appt-legend" role="list">
        {series.map((line, index) => (
          <span key={line.key} role="listitem">
            <i style={{ background: line.colour ?? SERIES_COLOURS[index % SERIES_COLOURS.length] }} />
            {line.label}
            {hover !== null && points[hover] && <strong> {points[hover][line.key] ?? 0}</strong>}
          </span>
        ))}
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Donut
// ---------------------------------------------------------------------------

export function DonutChart({
  slices,
  total,
  totalLabel,
  size = 200,
}: {
  slices: { label: string; value: number; share: number; colour?: string }[]
  total: number
  totalLabel: string
  size?: number
}) {
  const titleId = useId()
  const radius = size / 2 - 12
  const circumference = 2 * Math.PI * radius
  let offset = 0

  return (
    <div className="appt-row" style={{ gap: 20, alignItems: 'center', flexWrap: 'wrap' }}>
      <svg
        viewBox={`0 0 ${size} ${size}`}
        style={{ width: size, height: size, flex: 'none' }}
        role="img"
        aria-labelledby={titleId}
      >
        <title id={titleId}>
          {totalLabel}: {total}. {slices.map((slice) => `${slice.label} ${slice.share}%`).join(', ')}
        </title>

        <g transform={`rotate(-90 ${size / 2} ${size / 2})`}>
          {slices.map((slice, index) => {
            const length = (slice.share / 100) * circumference
            const dash = `${length} ${circumference - length}`
            const element = (
              <circle
                key={slice.label}
                cx={size / 2}
                cy={size / 2}
                r={radius}
                fill="none"
                stroke={slice.colour ?? SERIES_COLOURS[index % SERIES_COLOURS.length]}
                strokeWidth={22}
                strokeDasharray={dash}
                strokeDashoffset={-offset}
              />
            )
            offset += length
            return element
          })}
        </g>

        <text x={size / 2} y={size / 2 - 2} textAnchor="middle" fontSize={26} fontWeight={700} fill="#17231e">
          {new Intl.NumberFormat('en-IN').format(total)}
        </text>
        <text x={size / 2} y={size / 2 + 16} textAnchor="middle" fontSize={11} fill="#596b62">
          {totalLabel}
        </text>
      </svg>

      <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: 8, minWidth: 180, flex: 1 }}>
        {slices.map((slice, index) => (
          <li key={slice.label} style={{ display: 'grid', gridTemplateColumns: '10px 1fr auto auto', gap: 10, alignItems: 'center' }}>
            <i
              aria-hidden
              style={{
                width: 10,
                height: 10,
                borderRadius: 999,
                background: slice.colour ?? SERIES_COLOURS[index % SERIES_COLOURS.length],
              }}
            />
            <span style={{ minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
              {slice.label}
            </span>
            <span className="num" style={{ color: 'var(--muted)', fontSize: 12.5 }}>
              {slice.share}%
            </span>
            <span className="num" style={{ fontWeight: 650, fontSize: 12.5 }}>
              {new Intl.NumberFormat('en-IN').format(slice.value)}
            </span>
          </li>
        ))}
      </ul>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Sparkline
// ---------------------------------------------------------------------------

export function Sparkline({
  values,
  height = 40,
  colour = '#25b003',
  label,
}: {
  values: number[]
  height?: number
  colour?: string
  label: string
}) {
  const titleId = useId()

  if (values.length < 2) {
    return <div className="appt-tone-neutral" style={{ fontSize: 12 }}>Not enough history to draw a trend.</div>
  }

  const width = 240
  const max = Math.max(...values, 0.0001)
  const min = Math.min(...values, 0)
  const span = max - min || 1

  const path = values
    .map((value, index) => {
      const x = (index / (values.length - 1)) * width
      const y = height - ((value - min) / span) * (height - 4) - 2
      return `${index === 0 ? 'M' : 'L'} ${x} ${y}`
    })
    .join(' ')

  return (
    <svg
      viewBox={`0 0 ${width} ${height}`}
      style={{ width: '100%', height, display: 'block' }}
      role="img"
      aria-labelledby={titleId}
      preserveAspectRatio="none"
    >
      <title id={titleId}>{label}</title>
      <path d={path} fill="none" stroke={colour} strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />
    </svg>
  )
}

// ---------------------------------------------------------------------------
// Utilisation meter
// ---------------------------------------------------------------------------

export function UtilisationMeter({
  value,
  label,
  detail,
}: {
  value: number
  label: string
  detail?: string
}) {
  const clamped = Math.max(0, Math.min(100, value))
  const tone = clamped > 90 ? 'full' : clamped > 75 ? 'high' : undefined

  return (
    <div className="appt-meter-row">
      <div style={{ minWidth: 0 }}>
        <div className="appt-row" style={{ justifyContent: 'space-between', gap: 8, marginBottom: 6 }}>
          <strong style={{ fontWeight: 650, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
            {label}
          </strong>
          {detail && <span style={{ color: 'var(--muted)', fontSize: 12 }}>{detail}</span>}
        </div>
        <div className="appt-bar" data-tone={tone} role="img" aria-label={`${label}: ${Math.round(clamped)} per cent`}>
          <span style={{ width: `${clamped}%` }} />
        </div>
      </div>
      <span className="num" style={{ fontWeight: 700, minWidth: 48 }}>
        {Math.round(clamped)}%
      </span>
    </div>
  )
}
