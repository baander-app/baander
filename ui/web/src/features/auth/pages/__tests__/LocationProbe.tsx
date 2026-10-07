import { useLocation } from 'react-router-dom'

/** Shows the current router location so tests can assert navigation. */
export function LocationProbe() {
  const location = useLocation()

  return <span data-testid="location">{location.pathname + location.search + location.hash}</span>
}
