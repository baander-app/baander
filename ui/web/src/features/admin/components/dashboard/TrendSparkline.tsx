import styled from 'styled-components'

interface TrendSparklineProps {
  data: number[]
  width?: number
  height?: number
  color?: string
}

const StyledSvg = styled.svg`
  overflow: visible;
`

export function TrendSparkline({
  data,
  width = 120,
  height = 32,
  color = 'currentColor',
}: TrendSparklineProps) {
  if (data.length < 2) {
    return (
      <svg width={width} height={height}>
        <line
          x1={0}
          y1={height / 2}
          x2={width}
          y2={height / 2}
          stroke={color}
          strokeOpacity={0.2}
          strokeWidth={1}
        />
      </svg>
    )
  }

  const max = Math.max(...data)
  const min = Math.min(...data)
  const range = max - min || 1
  const padding = 2

  const points = data
    .map((v, i) => {
      const x = (i / (data.length - 1)) * (width - padding * 2) + padding
      const y = height - padding - ((v - min) / range) * (height - padding * 2)
      return `${x},${y}`
    })
    .join(' ')

  return (
    <StyledSvg width={width} height={height}>
      <polygon
        points={`${padding},${height - padding} ${points} ${width - padding},${height - padding}`}
        fill={color}
        fillOpacity={0.08}
      />
      <polyline
        points={points}
        fill="none"
        stroke={color}
        strokeWidth={1.5}
        strokeLinejoin="round"
      />
    </StyledSvg>
  )
}
